<?php

namespace PSP\Http;

/**
 * Exception class to be used for Http object. Adds new functions getLogID and getHTTPCode
 */
class HttpException extends \Exception
{
	private $log_id;
	private int $http_code;
	private bool $retry;
	private $error_data;
	private $auth_expired;
	
	/**
	 * Http exception
	 * @param string $message
	 * @param int $http_code
	 * @param string|array|null $log_id Single text log id, or array of datalogger ids to be json encoded (with the table prefixed. Eg. orders_in:xxx)
	 * @param int|null $status_code If different from http_code
	 * @param \Throwable|null $previous Optional if you are re-throwing a previous exception
	 * @param bool $retry Should this curl call be repeated. Default true
	 * @param string|array|object|null $error_data Optional additional data object to include in the Exception
	 * @param bool $auth_expired Did auth expire and requires a refresh? Only works if you've performed oauth_refresh_setup() on the curl object.
	 */
	public function __construct(string $message = "", int $http_code = 0, $log_id = null, ?int $status_code = null, ?\Throwable $previous = null, bool $retry = true, $error_data = null, bool $auth_expired = false)
	{
		$this->log_id = is_array($log_id) ? json_encode($log_id) : $log_id;
		$this->http_code = $http_code;
		$this->retry = $retry;
		$this->error_data = $error_data;
		$this->auth_expired = $auth_expired;
		
		parent::__construct(
			$message,
			!is_null($status_code) ? (int)$status_code : (int)$http_code,
			$previous
		);
	}
	
	/**
	 * Return log ID for this exception
	 * @return string
	 */
	public function getLogID()
	{
		return $this->log_id;
	}
	
	/**
	 * Return HTTP Status code for this exception
	 * @return int
	 */
	public function getHTTPCode()
	{
		return $this->http_code;
	}
	
	/**
	 * Return flag for should curl be repeated
	 * @return boolean
	 */
	public function getRetry()
	{
		return $this->retry;
	}
	
	/**
	 * Extra Error Data object that was set by the Error Checker
	 * @return mixed
	 */
	public function getErrorData()
	{
		return $this->error_data;
	}
	
	/**
	 * Return flag for whether auth is expired and should be refreshed. Will trigger auto-refresh if you set oauth_refresh_setup()
	 * @return boolean
	 */
	public function getAuthExpired()
	{
		return $this->auth_expired;
	}
}
