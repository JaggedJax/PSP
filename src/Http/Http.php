<?php

namespace PSP\Http;

use PSP\App\Config;
use PSP\Log\Log;
use PSP\Util\Crypt;
use PSP\Util\Strings;

use function Amp\delay;

/**
 * Curl Object wrapper for making curl calls and handling errors.
 * Do not re-initialize for each call<br>
 * Default curl params:<br>
 *		* CURLOPT_TIMEOUT & CURLOPT_CONNECTTIMEOUT = 60<br>
 *		* CURLOPT_RETURNTRANSFER = true<br>
 *		* CURLOPT_USERAGENT = {name}/1.0 (+https://{domain}) - see user_agent()<br>
 *		* CURLOPT_HEADERFUNCTION = [$this, 'parse_header_line']<br>
 *
 * Other default values:<br>
 *		* endpoint_param_separator = ?<br>
 *		* num_auto_retries = 1<br>
 *		* auto_retry_sleep_time = 2 sec<br>
 *		* rate_limit_http_status = 429<br>
 *		* rate_limit_sleep_time = 30 sec<br>
 */
class Http
{
	private $base_url;
	private $temp_base_url;
	private $temp_oauth_header;
	private $current_url = '';
	private $json;
	private float $startTime; // Time in ms when API call was started, for monitoring execution time
	private float $runTime; // Time in seconds it took for the API call to return
	
	private ?int $last_http_code;
	private $last_headers_sent = null;
	private $last_request = null;
	private $last_request_type = null;
	private $last_response = null;
	private $last_curl_error = null;
	private $last_log_id = null;
	
	private $last_graphql_type = null;
	
	private $global_get_params = [];
	private $global_headers = [];
	
	private string|array $param_builder_func = 'http_build_query';
	private array $param_builder_func_params = [];
	
	private $rand_header_name = '';
	private $rand_header_func = '';
	private $rand_header_params = [];
	
	private $parse_response_func = 'json_decode';
	private $parse_response_func_params = [true];
	
	private $post_builder_func = 'json_encode';
	private $post_builder_func_params = [];
	
	private $endpoint_param_separator = '?';
	
	private $response_error_check_func;
	private $response_error_check_func_params = [];
	
	private $oauth_refresh_params = [];
	
	/** @var array $response_headers Key value list of headers in response. Key always lowercase */
	private $response_headers = [];
	
	private $num_auto_retries = 1; // # times to auto retry
	private $auto_retry_sleep = 2; // # seconds to sleep
	
	private $rate_limit_sleep = 30; // # seconds to sleep
	private $rate_limit_http_code = [429]; // http codes to detect as over rate limit
	
	/** Request headers that are never logged, in lowercase. strip_credentials() adds to these */
	public const array SECRET_HEADERS = ['authorization', 'proxy-authorization', 'cookie'];

	private $strip_credentials = ['header' => []]; // List of get, header, and body fields to strip out when logging, added to SECRET_HEADERS
	
	private $ch;
	private Log $log;
	
	/**
	 * Initialize curl object. Keep this around. No need to re-initialize all the time<br>
	 * Default curl params:<br>
	 *		* CURLOPT_TIMEOUT & CURLOPT_CONNECTTIMEOUT = 60<br>
	 *		* CURLOPT_RETURNTRANSFER = true<br>
	 *		* CURLOPT_USERAGENT = {name}/1.0 (+https://{domain}) - see user_agent()<br>
	 *
	 * Other default values:<br>
	 *		* endpoint_param_separator = ?<br>
	 *		* num_auto_retries = 1<br>
	 *		* auto_retry_sleep_time = 2 sec<br>
	 *		* rate_limit_http_status = 429<br>
	 *		* rate_limit_sleep_time = 30 sec<br>
	 *
	 * @param string $base_url Ending "?" not recommended, it will be added automatically. It is recommended to end URL with a slash "/"
	 * @param Log $log
	 * @param array $global_curl_params Array of curl params to set. DO NOT PASS IN CURLOPT_HTTPHEADER! Key value pair eg: array(CURLOPT_XXXX => array('Content-Type: application/json'))
	 * @param boolean $json When true, calls will expect an array to convert to JSON. When false, calls will expect a SimpleXML object
	 */
	public function __construct(string $base_url, ?Log $log = null, array $global_curl_params = [], bool $json = true)
	{
		$this->log = $log ?? Log::getInstance();
		$this->json = $json;
		$this->base_url = trim($base_url);
		$this->response_error_check_func = self::default_error_checker(...);
		
		if (!$this->json) {
			// Unset default post_builder_func if not JSON
			$this->post_builder_func = '';
		}
		
		$this->curl_init();
		
		$default_curl_params = [
			CURLOPT_TIMEOUT => 60,
			CURLOPT_CONNECTTIMEOUT => 60,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERAGENT => self::user_agent(),
			CURLOPT_HEADERFUNCTION => [$this, 'parse_header_line'],
		];
		
		// Merge default curl params with any passed in
		if (!empty($global_curl_params)) {
			foreach ($global_curl_params as $key => $value) {
				$default_curl_params[$key] = $value;
			}
		}
		
		//Set curl params
		$this->set_curl_params($default_curl_params);
	}
	
	/**
	 * Default User-Agent for all outbound calls. Names us, a version, and a URL where a site owner can learn who is calling,
	 * which is the convention that bot protection and rate limiters trust more than a bare name.
	 * @return string Eg. "MyApp/1.0 (+https://example.com)"
	 */
	public static function user_agent(): string
	{
		$name = Config::get('psp')['name'] ?? 'PSP';
		$domain = Config::get('psp')['domain'] ?? '';
		return "$name/1.0" . ($domain !== '' ? " (+https://$domain)" : '');
	}
	
	/**
	 * Get the current Base URL
	 * @return string
	 */
	public function get_base_url()
	{
		return $this->base_url;
	}
	
	/**
	 * Change base_url for this curl object
	 * @param string $base_url
	 */
	public function change_base_url(string $base_url)
	{
		$this->base_url = $base_url;
	}

	/**
	 * Change base_url for this curl object for the next curl call. After that it goes back to the original base_url
	 * @param string $base_url
	 */
	public function change_base_url_once(string $base_url)
	{
		$this->temp_base_url = $base_url;
	}
	
	/**
	 * Return the http status code for the most recent API call
	 * @return int
	 */
	public function get_last_http_code()
	{
		return $this->last_http_code;
	}
	
	/**
	 * Return raw response string
	 * @return string
	 */
	public function get_last_raw_response()
	{
		return $this->last_response;
	}
	
	/**
	 * Clear last response from memory
	 * @return void
	 */
	public function clear_last_raw_response()
	{
		$this->last_response = null;
	}
	
	/**
	 * Return raw request string
	 * @return string
	 */
	public function get_last_raw_request()
	{
		return $this->last_request;
	}
	
	/**
	 * Get HTTP type of last request. EG. GET, POST, DELETE, etc
	 * @return string
	 */
	public function get_last_request_type()
	{
		return $this->last_request_type;
	}
	
	/**
	 * How many seconds for API response to be received and parsed
	 * @return float Seconds. Eg: 1.9389482
	 */
	public function get_last_run_time()
	{
		return $this->runTime;
	}
	
	/**
	 * Get Log ID for the last insert into the log (if one was done)
	 * @return string|null
	 */
	public function get_last_log_id(): ?string
	{
		return $this->last_log_id;
	}
	
	/**
	 * Get key/value list of response headers. All Keys are ALWAYS lowercase regardless of how they were received (HTTP2 standard)
	 * @return array Header name as lowercase Key. Value as value
	 */
	public function get_response_headers()
	{
		return $this->response_headers;
	}
	
	/**
	 * Get a specific response header
	 * @param string $header Response Header to get value for. Case insensitive
	 * @return string|null Response Header value
	 */
	public function get_response_header($header)
	{
		if (empty($header)) {
			return null;
		}
		
		return $this->response_headers[strtolower($header)] ?? null;
	}
	
	/**
	 * Get key/value list of request headers sent
	 * @return array Header name as Key. Value as value
	 */
	public function get_request_headers()
	{
		return $this->last_headers_sent;
	}
	
	/**
	 * Provide a list of get params to be passed with ALL curl calls.
	 * @param array $params key=>value pair
	 */
	public function set_global_get_params($params)
	{
		$this->global_get_params = $params;
	}
	
	/**
	 * Setup auto refresh of oauth key by calling desired function when http status detected
	 * @param int[] $http_statuses List of statuses to trigger an oauth refresh. [401] is the usual status
	 * @param callable $callable_function Function to call if token refresh is required. See here for format: http://php.net/manual/en/language.types.callable.php <br>
	 * @param array $function_params params to pass to function in this order
	 * @param callable $oauth_header_update_function Function to build the header string. Called with
	 *  no arguments here to build the starting header, and again with whatever $callable_function
	 *  returned after a triggered refresh. See here for format: http://php.net/manual/en/language.types.callable.php <br>
	 *		Should usually be: array($object_reference, 'build_oauth_header')
	 */
	public function oauth_refresh_setup(array $http_statuses, callable $callable_function, $function_params, callable $oauth_header_update_function)
	{
		$this->oauth_refresh_params = [
			'refresh_http_status' => $http_statuses,
			'callable_function' => $callable_function,
			'function_params' => $function_params,
			'oauth_header' => call_user_func($oauth_header_update_function),
			'oauth_header_update_function' => $oauth_header_update_function,
		];
	}
	
	/**
	 * Manually update oauth header in case the auth key was changed elsewhere
	 * @param string $oauth_header
	 */
	public function update_oauth_header($oauth_header)
	{
		$this->oauth_refresh_params['oauth_header'] = $oauth_header;
	}
	
	/**
	 * Manually update oauth header for the next API call only
	 * @param string $oauth_header
	 */
	public function update_oauth_header_once($oauth_header)
	{
		$this->temp_oauth_header = $oauth_header;
	}
	
	/**
	 * Provide a list of headers to be passed with ALL curl calls.
	 * @param string[] $headers numerically indexed array of header strings
	 */
	public function set_global_headers($headers)
	{
		$this->global_headers = $headers;
	}
	
	/**
	 * Get a list of global headers that are currently setup
	 * @return array Numerically indexed array of header strings
	 */
	public function get_global_headers()
	{
		return $this->global_headers;
	}
	
	/**
	 * Change any curl params
	 * @param array $curl_params Array of curl params to set. Key value pair eg: array(CURLOPT_HTTPHEADER => array('Content-Type: application/json'))
	 */
	public function set_curl_params($curl_params)
	{
		if (!empty($curl_params)) {
			curl_setopt_array($this->ch, $curl_params);
		}
	}
	
	/**
	 * Set how many times to retry future API calls if a failure is detected.
	 * @param int $num_retries Number of times to retry if a failure is detected. Default is 1
	 */
	public function set_num_auto_retries($num_retries)
	{
		$this->num_auto_retries = $num_retries;
	}
	
	/**
	 * Set how many seconds to sleep before performing an auto retry
	 * @param int $num_seconds_sleep Number of seconds to sleep between retries. Default is 2
	 */
	public function set_auto_retry_sleep_time($num_seconds_sleep)
	{
		$this->auto_retry_sleep = $num_seconds_sleep;
	}
	
	/**
	 * Set which http_code(s) should be detected as being over the rate limit
	 * @param int|int[] $http_code Default is 429
	 */
	public function set_http_rate_limit_code($http_code)
	{
		if (!empty($http_code) && !is_array($http_code)) {
			$http_code = [$http_code];
		}
		$this->rate_limit_http_code = $http_code;
	}
	
	/**
	 * Set how many seconds to sleep before retrying when we've gone over the rate limit
	 * @param int $num_seconds_sleep Default is 30
	 */
	public function set_http_rate_limit_sleep($num_seconds_sleep)
	{
		$this->rate_limit_sleep = $num_seconds_sleep;
	}
	
	/**
	 * List fields to hide when logging. The SECRET_HEADERS are always hidden
	 * @param string[] $headers List of header field names, any case. EG: ['X-Api-Key']
	 * @param string[] $get_params List of field names. EG: ['password','api_key']
	 * @param string[] $data_fields List of field names. EG: ['password','api_key']
	 */
	public function strip_credentials(array $headers = [], array $get_params = [], array $data_fields = [])
	{
		$this->strip_credentials = [
			'header' => $headers,
			'get' => $get_params,
			'data' => $data_fields, // TODO: Define and support this
		];
	}
	
	/**
	 * Set which character to use when concatenating the URL endpoint and GET params
	 * @param string $separator Pass empty string for no separator. Default is: ?
	 */
	public function set_endpoint_param_separator(string $separator)
	{
		$this->endpoint_param_separator = $separator;
	}
	
	/**
	 * Perform GET call.
	 * @param string $endpoint
	 * @param array $url_params key=>value pairs. "?" will be added automatically
	 * @param array $header numerically indexed array of header strings
	 * @param boolean $log_response
	 * @return mixed Parsed Response
	 * @throws HttpException
	 */
	public function get(string $endpoint = '', array $url_params = [], array $header = [], bool $log_response = true)
	{
		return $this->make_curl_call('GET', $endpoint, $url_params, [], $header, $log_response);
	}
	
	/**
	 * Perform POST call. If object initialized as JSON type, post_data will be converted from array to JSON
	 * @param string $endpoint
	 * @param array $url_params key=>value pairs. "?" will be added automatically
	 * @param array $post_data
	 * @param array $header numerically indexed array of header strings
	 * @param boolean $log_response
	 * @return mixed Parsed Response
	 * @throws HttpException
	 */
	public function post(string $endpoint = '', array $url_params = [], array $post_data = [], array $header = [], bool $log_response = true)
	{
		return $this->make_curl_call('POST', $endpoint, $url_params, $post_data, $header, $log_response);
	}
	
	/**
	 * Perform PUT call. If object initialized as JSON type, post_data will be converted from array to JSON
	 * @param string $endpoint
	 * @param array $url_params key=>value pairs. "?" will be added automatically
	 * @param array $post_data
	 * @param array $header numerically indexed array of header strings
	 * @param boolean $log_response
	 * @return mixed Parsed Response
	 * @throws HttpException
	 */
	public function put(string $endpoint = '', array $url_params = [], array $post_data = [], array $header = [], bool $log_response = true)
	{
		return $this->make_curl_call('PUT', $endpoint, $url_params, $post_data, $header, $log_response);
	}
	
	/**
	 * Perform PATCH call. If object initialized as JSON type, post_data will be converted from array to JSON
	 * @param string $endpoint
	 * @param array $url_params key=>value pairs. "?" will be added automatically
	 * @param array $post_data
	 * @param array $header numerically indexed array of header strings
	 * @param boolean $log_response
	 * @return mixed Parsed Response
	 * @throws HttpException
	 */
	public function patch(string $endpoint = '', array $url_params = [], array $post_data = [], array $header = [], bool $log_response = true)
	{
		return $this->make_curl_call('PATCH', $endpoint, $url_params, $post_data, $header, $log_response);
	}
	
	/**
	 * Perform DELETE call. If object initialized as JSON type, post_data will be converted from array to JSON
	 * @param string $endpoint
	 * @param array $url_params key=>value pairs. "?" will be added automatically
	 * @param array $post_data
	 * @param array $header numerically indexed array of header strings
	 * @param boolean $log_response
	 * @return mixed Parsed Response
	 * @throws HttpException
	 */
	public function delete(string $endpoint = '', array $url_params = [], array $post_data = [], array $header = [], bool $log_response = true)
	{
		return $this->make_curl_call('DELETE', $endpoint, $url_params, $post_data, $header, $log_response);
	}
	
	/**
	 * Pass the name of the function we should call when building the parameters query. First param passed will be the GET param array. By default we call http_build_query.<br>
	 *	Examples:<br>
	 *		* Global function: 'http_build_query'<br>
	 *		* Static function in object: '\NAMESPACE\Foo::test'<br>
	 *		* Function in object: array('\NAMESPACE\Foo', 'test')<br>
	 *	Leave off the "NAMESPACE\" portion if your object is not namespaced<br>
	 *
	 * @param callable $function_name
	 * @param mixed[] $addtl_params Array of params to be passed to function. First param will always be the GET param array
	 */
	public function custom_param_builder(callable $function_name, array $addtl_params = [])
	{
		$this->param_builder_func = $function_name;
		$this->param_builder_func_params = $addtl_params;
	}
	
	/**
	 * Add a header with random data to each request. Will be included in logs
	 * @param string $rand_header_name Header Name
	 * @param callable $rand_header_function eg: Crypt::rand_alphanumeric'
	 * @param mixed[] $function_params Array of params to be passed to function.
	 */
	public function random_header(string $rand_header_name, callable $rand_header_function, array $function_params = [])
	{
		$this->rand_header_name = $rand_header_name;
		$this->rand_header_func = $rand_header_function;
		$this->rand_header_params = $function_params;
	}
	
	/**
	 * Format URL params
	 * @param array $url_params Key/Value array of GET params
	 * @return string Encoded URL string
	 */
	private function build_parameters_query(array $url_params)
	{
		return call_user_func_array(
			$this->param_builder_func,
			array_merge([$url_params], $this->param_builder_func_params)
		);
	}
	
	/**
	 * Pass the name of the function we should call when parsing the CURL response. First param passed will be the CURL response string. By default we call json_decode($result, true).<br>
	 *	Examples:<br>
	 *		* Global function: 'json_decode'<br>
	 *		* Static function in object: '\NAMESPACE\Foo::test'<br>
	 *		* Function in object: array('\NAMESPACE\Foo', 'test')<br>
	 *	Leave off the "NAMESPACE\" portion if your object is not namespaced<br>
	 *
	 * @param callable $function_name Pass empty string to perform no parsing
	 * @param array $addtl_params Array of params to be passed to function. First param will always be string response from CURL
	 */
	public function custom_response_parser(callable $function_name, array $addtl_params = [])
	{
		$this->parse_response_func = $function_name;
		$this->parse_response_func_params = $addtl_params;
	}
	
	/**
	 * Pass the name of the function we should call when building the POST body text. First param passed will be the CURL response string. By default we call json_encode($data) if the connection type is JSON.<br>
	 *	Examples:<br>
	 *		* Global function: 'json_encode'<br>
	 *		* Static function in object: '\NAMESPACE\Foo::test'<br>
	 *		* Function in object: array('\NAMESPACE\Foo', 'test')<br>
	 *	Leave off the "NAMESPACE\" portion if your object is not namespaced<br>
	 *
	 * @param callable $function_name Pass empty string to perform no modifications
	 * @param array $addtl_params Array of params to be passed to function. First param will always be the POST data
	 */
	public function custom_post_builder(callable $function_name, array $addtl_params = [])
	{
		$this->post_builder_func = $function_name;
		$this->post_builder_func_params = $addtl_params;
	}
	
	/**
	 * Get the current setup POST builder function
	 * @return array{function:string, params:array}
	 */
	public function get_custom_post_builder(): array
	{
		return ['function' => $this->post_builder_func, 'params' => $this->post_builder_func_params];
	}
	
	/**
	 * Parse $this->last_response before error checking and returning
	 * @return mixed
	 */
	private function parse_last_response()
	{
		if (empty($this->parse_response_func)) {
			return $this->last_response;
		}
		
		// Before parsing, check if this is a really large payload and we are low on memory
		Strings::increaseMemoryLimitOnLargeString($this->last_response);
		
		return @call_user_func_array(
			$this->parse_response_func,
			array_merge([$this->last_response], $this->parse_response_func_params)
		);
	}
	
	/**
	 * Convert header array from associative key/value array to an indexed array
	 * @param array $assoc_headers Key/Value
	 * @param array $strip_headers List of header names to have their values hidden/removed
	 * @return array Numerically index headers
	 */
	public static function header_convert(array $assoc_headers, array $strip_headers = [])
	{
		// Do nothing is headers empty or already numerically indexed
		if (empty($assoc_headers) || isset($assoc_headers[0])) {
			return $assoc_headers;
		}
		
		$headers = [];
		foreach ($assoc_headers as $key => $value) {
			if (!empty($strip_headers) && in_array($key, $strip_headers)) {
				$value = '**REMOVED**';
			}
			// In case the value is an array of string instead of a single string, convert it back to a single string
			if (is_array($value)) {
				$value = implode(", ", $value);
			}
			$headers[] = "$key: $value";
		}
		return $headers;
	}
	
	/**
	 * Hide the values of secret request headers
	 * @param string[] $headers Header strings. EG: "Authorization: Bearer abc"
	 * @param string[] $secret_names Header names to hide, added to SECRET_HEADERS. Any case
	 * @return string[]
	 */
	public static function strip_headers(array $headers, array $secret_names = []): array
	{
		$secret_names = array_merge(self::SECRET_HEADERS, array_map('strtolower', $secret_names));
		return array_map(static function ($header) use ($secret_names) {
			$name = explode(':', (string)$header, 2)[0];
			return in_array(strtolower(trim($name)), $secret_names, true) ? "$name: **REMOVED**" : $header;
		}, $headers);
	}
	
	/**
	 * Log the most recent call to the datalogger. Sets $this->last_datalogger_id.
	 * Falls back to traditional log if Datalogger unavailable
	 * @param HttpException|null $exception Any exception that was thrown
	 * @param bool $log_response If false then the data of the response will not be logged
	 * @return void
	 */
	private function log_to_datalogger(?HttpException $exception = null, bool $log_response = true)
	{
		$this->last_log_id = null;
		
		$error = null;
		if (!empty($exception)) {
			$error['message'] = $exception->getMessage();
			$error['code'] = $exception->getCode();
		}
		
		// Sanitize header data before logging
		$request_headers = self::strip_headers($this->last_headers_sent ?? [], $this->strip_credentials['header'] ?? []);
		
		// Format header as numerically indexed array for MongoDB
		$response_headers = self::header_convert($this->response_headers);
		
		// Sanitize URL data before logging
		$url = $this->current_url;
		$url_modified = false;
		if (!empty($this->strip_credentials['get'])) {
			$parsed_url = @parse_url($this->current_url);
			if (!empty($parsed_url['query'])) {
				$url_params = [];
				parse_str($parsed_url['query'], $url_params);
				if (!empty($url_params)) {
					foreach ($url_params as $key => $value) {
						if (in_array($key, $this->strip_credentials['get'])) {
							$url_params[$key] = '__REMOVED__';
							$url_modified = true;
						}
					}
					if ($url_modified) {
						$url = "{$parsed_url['scheme']}://{$parsed_url['host']}";
						if (!empty($parsed_url['port'])) {
							$url .= ":{$parsed_url['port']}";
						}
						$url .= $parsed_url['path'] . $this->endpoint_param_separator . $this->build_parameters_query($url_params);
						if (!empty($parsed_url['fragment'])) {
							$url .= "#{$parsed_url['fragment']}";
						}
					}
				}
			}
		}
		
		// Sanitize request and response
		if (!empty($this->strip_credentials['data'])) {
			//TODO:
			//	if $this->json && $this->strip_credentials['data']['request'], then json decode $this->last_request
			//  if $this->json && $this->strip_credentials['data']['response'], then json decode $this->last_response
			// Update the decoded arrays to strip data
			// Re-encode the arrays.
			// Pass these copies as stings to prepare_document
			// Don't actually overwrite $this->last_request and $this->last_response
			// Document how to pass the field list in strip_credentials()
			/*
			foreach($this->strip_credentials['data']['request'] as $element){
				if(is_string($element)){
					if(isset($last_request_array[$element])){
						$last_request_array[$element] = '**REMOVED**';
					}
				}
			}
			foreach($this->strip_credentials['data']['response'] as $element){
				if(is_string($element)){
					if(isset($last_response_array[$element])){
						$last_response_array[$element] = '**REMOVED**';
					}
				}
			}
			*/
		}
		
		$type = (!empty($this->last_graphql_type)) ? $this->last_graphql_type : $this->last_request_type;
		
		
		$document = [
			'request' => [
				// Request
				'type' => $type,
				'url' => $url,
				'data' => $this->last_request,
				//TODO: Have proper type handling by setting in Http constructor
				'format' => $this->json ? 'json' : 'xml',
				'headers' => $request_headers,
			],
			'response' => [
				// Response
				'data' => $log_response ? $this->last_response : 'Response Logging Disabled',
				'format' => $this->json ? 'json' : 'xml',
				'headers' => $response_headers,
				'http_code' => $this->last_http_code,
				'error' => $error,
			],
			'runtime' => $this->runTime,
		];
		$log_message = 'HTTP '.trim($document['request']['type'].' '.substr($document['request']['url'], 0, 100));
		
		$this->last_log_id = Log::info($log_message, $document);
	}
	
	/**
	 * Pass the name of the function we should call after parsing to check for errors. First 3 params passed will be the parsed response, http status code (int), Log object. Nothing called by default.<br>
	 * This error checking function MUST either throw an error of type HttpException, or return nothing at all.<br>
	 * <br>
	 *	Examples:<br>
	 *		* Global function: 'generic_parser'<br>
	 *		* Static function in object: '\NAMESPACE\Foo::test'<br>
	 *		* Public function in object: array('\NAMESPACE\Foo', 'test')<br>
	 *	Leave off the "NAMESPACE\" portion if your object is not namespaced<br>
	 *
	 * @param callable $function_name Pass empty string to perform no checking
	 * @param array $addtl_params Array of params to be passed to function. First 3 params will always be parsed response from CURL, http status code, & Log object
	 */
	public function custom_error_checker(callable $function_name, array $addtl_params = [])
	{
		$this->response_error_check_func = $function_name;
		$this->response_error_check_func_params = $addtl_params;
	}
	
	/**
	 * Default error checker function. YOU MUST replace this by calling custom_error_checker
	 * @param array|string $response
	 * @param int $http_status
	 * @param Log $log
	 * @throws HttpException
	 */
	private static function default_error_checker($response, $http_status, Log $log)
	{
		if ($http_status == 0 || $http_status >= 400 || empty($response)) {
			$log_id = $log->warning("API error response", is_array($response) ? $response : [$response]);
			$message = 'Unknown API Error. custom_error_checker() Not Specified!';
			//trigger_error($message, E_USER_WARNING);
			throw new HttpException($message, $http_status, $log_id, $http_status, null, false, $response);
		}
	}
	
	/**
	 * Parse response before error checking and returning
	 * @param mixed $parsed_response
	 * @param int $http_code
	 * @return mixed
	 * @throws HttpException
	 */
	private function check_errors($parsed_response, $http_code)
	{
		if (empty($this->response_error_check_func)) {
			return null;
		}
		
		return call_user_func_array(
			$this->response_error_check_func,
			array_merge([$parsed_response, (int)$http_code, $this->log], $this->response_error_check_func_params)
		);
	}
	
	/**
	 * Curl Init. Does not need to be called unless you close curl.
	 */
	public function curl_init(): void
	{
		if (!empty($this->ch)) {
			unset($this->ch);
		}
		$this->current_url = null;
		$this->ch = curl_init();
	}
	
	/**
	 * Curl Close. Do not close curl unless you have a good reason to.
	 */
	public function curl_close(): void
	{
		if (!empty($this->ch)) {
			curl_close($this->ch);
		}
		unset($this->ch);
	}
	
	/**
	 * Parse the value of a Link header into a usable array.
	 * WARNING: DOES NOT WORK IF URL HAS A COMMA IN IT!
	 * WARNING: Expects one rel param per link.
	 * @param string $link_header
	 * @return array|null Key is the link rel value. Value is the URL.
	 */
	public static function parse_link_header(string $link_header): ?array
	{
		if (strlen($link_header) == 0) {
			return null;
		}
		
		$parts = explode(',', $link_header);
		$links = [];
		
		foreach ($parts as $p) {
			$section = explode(';', $p);
			if (count($section) != 2) {
				continue;
			}
			$url = trim(preg_replace("/<(.*)>/", '$1', $section[0]));
			$name = trim(preg_replace("/rel=\"(.*)\"/", '$1', $section[1]));
			$links[$name] = $url;
		}
		return $links;
	}
	
	/**
	 * Internal function to perform all curl call steps: prepare call, make call, parse result, and handle errors & retry
	 * @param string $request_type GET|POST|PUT
	 * @param string $endpoint
	 * @param array $url_params "?" will be added automatically
	 * @param array $post_data
	 * @param array $header numerically indexed array of header strings
	 * @param boolean $log_response If false and datalogger is used then the body of the response isn't logged
	 * @return mixed Response
	 * @throws HttpException|\Exception
	 */
	private function make_curl_call(string $request_type, string $endpoint, array $url_params = [], array $post_data = [], array $header = [], bool $log_response = true)
	{
		if (!empty($GLOBALS['unit_test_strict'])) {
			throw new \Exception('API Calls not allowed in Unit Tests!');
		}
		
		$num_retries = $this->num_auto_retries;
		$this->last_request_type = $request_type;
		$result = null;
		
		do {
			// Reset any stored request data
			$this->last_request = $this->last_headers_sent =
			$this->last_response = $this->last_curl_error =
			$this->last_http_code = $this->last_log_id = null;
			$this->startTime = microtime(true);
			
			$num_retries--;
			$retry = false;
			// Clear out memory from any previous API calls that may not be released yet, before we parse the response and potentially use a lot of memory
			gc_collect_cycles();
			
			try {
				$this->pre_call($request_type, $endpoint, $url_params, $post_data, $header);
				
				$this->last_response = curl_exec($this->ch);
				
				$result = $this->post_call($log_response, $request_type);
				
				$this->runTime = microtime(true) - $this->startTime;
			}
			catch (HttpException $e) {
				$retry = true;
				$this->runTime = microtime(true) - $this->startTime;
				
				// Always sleep for over rate limit, even if not retrying
				if (!empty($this->rate_limit_http_code) && !empty($this->rate_limit_sleep) && in_array($e->getHTTPCode(), $this->rate_limit_http_code)) {
					$this->log->warning("Over rate limit. Sleeping {$this->rate_limit_sleep} seconds");
					delay($this->rate_limit_sleep);
				}
				
				// Detect and refresh oauth access token
				if (
					// Detect HTTP code indicating Auth is expired
					(!empty($this->oauth_refresh_params['refresh_http_status']) && in_array($this->last_http_code, $this->oauth_refresh_params['refresh_http_status']))
					// OR if Exception explicitly requests a refresh
					|| (!empty($this->oauth_refresh_params['callable_function']) && $e->getAuthExpired())
				) {
					$this->log->info("HTTP Status {$this->last_http_code}. Auto refresh oAuth access token.");
					
					//Returns new access_token if changed
					$ret = call_user_func_array(
						$this->oauth_refresh_params['callable_function'],
						$this->oauth_refresh_params['function_params']
					);
					
					//If access_token is returned, update the oauth header in the object!
					if ($ret) {
						$this->log->info("Access token changed. Updating oauth_header");
						$this->oauth_refresh_params['oauth_header'] = call_user_func_array(
							$this->oauth_refresh_params['oauth_header_update_function'],
							[$ret]
						);
						
					}
				}
				
				// Always log error to datalogger - Previously this was only logged on the final attempt
				$this->log_to_datalogger($e);
				
				if ($num_retries < 0 || !$e->getRetry()) {
					$this->temp_base_url = null;
					$this->temp_oauth_header = null;
					$this->last_graphql_type = null;
					
					throw $e;
				}
				
				$this->log->warning("Call failed. Retrying ".($num_retries + 1)." more times in {$this->auto_retry_sleep} seconds.");
				delay($this->auto_retry_sleep);
			}
		}
		while ($retry && $num_retries >= 0);
		
		// Help clear memory after we've parse the API response
		gc_collect_cycles();
		
		$this->log_to_datalogger(null, $log_response);
		
		$this->temp_base_url = null;
		$this->temp_oauth_header = null;
		$this->last_graphql_type = null;
		
		return $result;
	}
	
	/**
	 * Perform all curl setup. Next step is to call curl_exec.
	 * @param string $endpoint
	 * @param array $url_params "?" will be added automatically
	 * @param array $post_data
	 * @param array $header
	 * @param string $request_type GET|POST|PUT
	 */
	private function pre_call(string $request_type, string $endpoint, array $url_params, $post_data, array $header)
	{
		if (empty($header)) {
			$header = [];
		}
		$this->response_headers = [];
		$rand_header = null;
		// Set URL, combining base and endpoint
		if (!empty($this->temp_base_url)) {
			$url = $this->temp_base_url . $endpoint;
			// $this->temp_base_url will be set to NULL after the call, in case it needs to be retried
		}
		else {
			$url = $this->base_url . $endpoint;
		}
		// Add GET params to URL
		if ($url_params || $this->global_get_params) {
			if (empty($url_params)) {
				$url_params = [];
			}
			if (empty($this->global_get_params)) {
				$this->global_get_params = [];
			}
			
			$url .= $this->endpoint_param_separator . $this->build_parameters_query(array_merge($this->global_get_params, $url_params));
		}
		
		if ($url !== $this->current_url) {
			$this->current_url = $url;
			curl_setopt($this->ch, CURLOPT_URL, $this->current_url);
		}
		
		if (!empty($post_data)) {
			
			// Format POST data as requested
			if (!empty($this->post_builder_func)) {
				$post_data = @call_user_func_array(
					$this->post_builder_func,
					array_merge([$post_data], $this->post_builder_func_params)
				);
			}
			// If ($post_data = false) we throw an exception
			if ($post_data === false) {
				throw new HttpException("Unable to format requested post data", 0);
			}
			
			$this->last_request = $post_data;
		}
		
		
		// put in random header here!
		if (!empty($this->rand_header_name) && !empty($this->rand_header_func)) {
			$rand_header = $this->rand_header_name.': '.call_user_func_array($this->rand_header_func, $this->rand_header_params);
			$header[] = $rand_header;
		}
		// put in oauth header here!
		if (!empty($this->temp_oauth_header)) {
			$header[] = $this->temp_oauth_header;
			$header = array_unique($header);
		}
		elseif (!empty($this->oauth_refresh_params['oauth_header'])) {
			$header[] = $this->oauth_refresh_params['oauth_header'];
			$header = array_unique($header);
		}
		
		if (!empty($header) || !empty($this->global_headers)) {
			//$this->log->logInfo('Headers', array_merge($this->global_headers, $header));
			$this->last_headers_sent = array_unique(array_merge($this->global_headers, $header));
			//$this->log->logInfo('Headers', array_merge($this->global_headers, $header));
			//curl_setopt($this->ch, CURLINFO_HEADER_OUT, true);
		}
		else {
			$this->last_headers_sent = [];
		}
		
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->last_headers_sent);
		
		// Set Request type and Post data
		if (!empty($post_data)) {
			//$this->log->logInfo('Posting Data');
			curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $request_type);
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, $post_data);
		}
		else {
			curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $request_type);
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, '');
		}
	}
	
	/**
	 * Parse and error handling of curl response
	 * @param boolean $log_response
	 * @param string $request_type GET|POST|PUT
	 * @return mixed Parsed response
	 * @throws HttpException
	 */
	private function post_call($log_response, $request_type)
	{
		$this->last_curl_error = curl_error($this->ch);
		$this->last_http_code = $http_status = curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);
		
		if (!empty($this->last_curl_error) || $http_status === 0 ||
			(!empty($this->rate_limit_http_code) && !empty($this->rate_limit_sleep) && in_array($http_status, $this->rate_limit_http_code))
		) {
			if (empty($this->last_curl_error)) {
				$this->last_curl_error = 'Over API Rate Limit';
			}
			$log_id = $this->log->error("$request_type Response ($http_status) CURL Error: {$this->last_curl_error}");
			throw new HttpException($this->last_curl_error, $http_status, $this->last_log_id ?? $log_id);
		}
		
		// Parse response as requested
		$parsed_response = $this->parse_last_response();
		
		//parsed response could be, array, xml, data object etc.
		if (is_array($parsed_response)) {
			//if it is empty, still return empty array instead of raw data!
			$this->check_errors($parsed_response, $http_status);
		}
		else {
			$this->check_errors(!empty($parsed_response) ? $parsed_response : $this->last_response, $http_status);
		}
		
		return $parsed_response;
	}
	
	/**
	 * This function is called by CURL once for each header so that we can collect and store the headers.
	 * Forces all Header keys to lowercase
	 * @param resource $ch
	 * @param string $header_line
	 * @return int # Of Bytes in Header
	 */
	private function parse_header_line($ch, $header_line)
	{
		$header_len = strlen($header_line);
		$header_line = rtrim($header_line);
		
		$split_header = explode(':', $header_line, 2);
		// Check if header value is not set
		if (!isset($split_header[1])) {
			$split_header[1] = null;
		}
		
		$key = trim($split_header[0]);
		if (!empty($key)) {
			$this->response_headers[strtolower($key)] = trim($split_header[1]);
		}
		
		return $header_len;
	}
	
	/**
	 * Executes a GraphQL Query using a HttpGraphObj
	 * @param string $endpoint Can be empty string
	 * @param array $url_params key=>value pairs. "?" will be added automatically
	 * @param HttpGraphObj $qo The query object
	 * @param array $header numerically indexed array of header strings
	 * @param bool $log_response
	 * @return mixed Parsed Response
	 * @throws HttpException
	 */
	public function query(string $endpoint, array $url_params, HttpGraphObj $qo, array $header = [], bool $log_response = true)
	{
		$body = [
			'query'	=> $qo->get_query(),
		];
		
		$variables	= $qo->get_variables();
		if (!empty($variables)) {
			$body['variables'] = $variables;
		}
		
		$this->last_graphql_type = $qo->type.' '.$qo->name;
		
		$result =  $this->post($endpoint, $url_params, $body, $header, $log_response);
		return $result;
	}
	
	/**
	 * Download a file from a URL and store it locally. Standlone function that does not use any other CRCurl functionality
	 * Suitable for large files.
	 * @param string $url
	 * @param string $local_file Full File path. File need not exist yet
	 * @param array $headers Optional headers to pass
	 * @param int $num_retries Number of times to re-attempt on failure
	 * @throws HttpException
	 * @return void
	 */
	public static function download_file(string $url, string $local_file, array $headers = [], int $num_retries = 1): void
	{
		// Open local file
		$fh = fopen($local_file, 'w+');
		if ($fh === false) {
			throw new HttpException("Unable to create file: $local_file", 0);
		}
		
		// Set remote URL
		$ch = curl_init($url);
		// Ensure a long curl timeout for large files
		curl_setopt($ch, CURLOPT_TIMEOUT, 600);
		curl_setopt($ch, CURLOPT_USERAGENT, self::user_agent());
		if (!empty($headers)) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}
		curl_setopt($ch, CURLOPT_FILE, $fh);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_exec($ch);
		$error = curl_error($ch);
		$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		
		fclose($fh);
		
		// Check for errors
		if (empty($http_status) || !empty($error) || $http_status >= 400) {
			$num_retries--;
			if ($num_retries >= 0) {
				delay(10);
				self::download_file($url, $local_file, $headers, $num_retries);
				return;
			}
			else {
				$error_message = $error ?: 'Invalid response from server.';
				$error_message .= " HTTP Status: $http_status";
				throw new HttpException($error_message, $http_status);
			}
		}
		
		// Check for empty file and retry
		if (filesize($local_file) == 0) {
			$num_retries--;
			Log::warning("EMPTY FILE ($http_status): $url");
			if ($num_retries >= 0) {
				delay(10);
				self::download_file($url, $local_file, $headers, $num_retries);
				return;
			}
		}
		
	}
}
