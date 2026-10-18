<?php

namespace PSP\Log;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use PSP\App\Config;
use PSP\App\Globals;
use PSP\App\Server;
use PSP\Util\Crypt;

class Log
{
	/** @var array<string, Log> $instance */
	private static array $instance = [];
	
	private Logger $log;
	
	private array $extra_elements = [];
	
	/**
	 * @param string $channel
	 */
	private function __construct(string $channel)
	{
		$this->log = new Logger($channel);
		// Sanitize channel name
		$channel = preg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $channel).'-'.date('Ymd');
		
		$log_dir = rtrim(Config::get('psp')['log_dir'] ?? '', '/');
		$logPath = $log_dir !== '' ? "$log_dir/$channel.log" : 'php://stderr';
		if ($logPath !== 'php://stderr') {
			// A file that already exists can be owned by another user (eg. created by a job), so check the file itself
			$writable = file_exists($logPath) ? is_writable($logPath) : is_writable(dirname($logPath));
			if (!$writable) {
				// Fallback to stderr if the custom log file is not writable. PHP-FPM drops worker stderr unless catch_workers_output
				// is on, so also report it with error_log(), which reaches the PHP-FPM / system log
				error_log("Log: $logPath is not writable by " . (get_current_user() ?: 'this user') . ' - logging to stderr instead');
				$logPath = "php://stderr";
			}
		}
		$handler = new StreamHandler(
			$logPath,
			(Globals::instance()->environment == 'local') ? Level::Debug : Level::Info,
			filePermission: 0664
		);
		$handler->setFormatter(new JsonLogFormatter());
		$this->log->pushHandler($handler);
		$this->log->pushProcessor(new LogProcessor());
	}
	
	/**
	 * Get log instance for a specific log channel. Generally not needed - Just call the log level you need directly!
	 * @param string $channel
	 * @return Log
	 */
	public static function getInstance(string $channel = 'Default'): Log
	{
		if (!isset(self::$instance[$channel])) {
			self::$instance[$channel] = new self($channel);
		}
		return self::$instance[$channel];
	}
	
	/**
	 * Resets the singleton instance. Primarily for testing purposes.
	 * @param ?string $channel Channel to unset. NULL to unset all Log channels
	 */
	public static function reset(?string $channel = null): void
	{
		if (empty($channel)) {
			self::$instance = [];
		}
		else {
			unset(self::$instance[$channel]);
		}
	}
	
	/**
	 * Get data to be injected into the log extra field
	 * @param Level $logLevel
	 * @param int $stacktrace_skip Number of lines of the stacktrace to skip
	 * @return array{log_id:string, process_id:string, process_name:string, pid:int|false, hostname:string, stacktrace?:list<string>}
	 */
	private function preLog(Level $logLevel, int $stacktrace_skip = 0): array
	{
		$prelog = [
			'log_id'     => date('ymdHis') . '-' . Crypt::rand_alphanumeric(6),
			'process_id' => Globals::instance()->process_id,
			'process_name' => Globals::instance()->process_name,
			'pid'        => getmypid(),
			'hostname'   => gethostname() ?: 'unknown',
			'environment' => Globals::instance()->environment,
		];
		
		if (!empty($this->extra_elements)) {
			$prelog = array_merge($prelog, $this->extra_elements);
		}
		
		// Include full stacktrace
		if ($logLevel->value > Level::Warning->value) {
			$prelog['stacktrace'] = Server::stack_trace(2);
		} // Include most recent stacktrace line
		else {
			$prelog['stacktrace'] = Server::stack_trace(2 + $stacktrace_skip, 1);
		}
		
		return $prelog;
	}
	
	/**
	 * Add an extra element to be included in all following logs
	 * @param string $key
	 * @param mixed $value
	 * @return void
	 */
	public static function set_element(string $key, mixed $value): void
	{
		$logObj = Log::getInstance();
		$logObj->extra_elements[$key] = $value;
		// Also tag the OpenTelemetry root span so traces can be searched by the same IDs
		Telemetry::tag($key, $value);
	}
	
	/**
	 * Add app ID fields (EG: customer_id) that are kept as top-level log fields, and by default also tagged on the trace root span
	 * @param list<string> $fields
	 * @param bool $trace Also tag the OpenTelemetry root span with these fields when set with set_element()
	 */
	public static function add_fields(array $fields, bool $trace = true): void
	{
		LogProcessor::add_fields(...$fields);
		if ($trace) {
			Telemetry::add_taggable_ids(...$fields);
		}
	}

	/**
	 * Remove an extra element from future logs
	 * @param string $key
	 * @return void
	 */
	public static function clear_element(string $key): void
	{
		$logObj = Log::getInstance();
		unset($logObj->extra_elements[$key]);
	}
	
	/**
	 * Clear all extra elements from future logs
	 * @return void
	 */
	public static function clear_all_elements(): void
	{
		$logObj = Log::getInstance();
		$logObj->extra_elements = [];
	}
	
	/**
	 * Normalize the $object argument accepted by the log level methods into an array suitable for merging
	 * into the Monolog context. Arrays are passed through as-is; strings and objects are wrapped under
	 * an 'object' key.
	 * @param array<string, mixed>|object|string $object
	 * @return array<string, mixed>
	 */
	private static function normalize_object(array|object|string $object): array
	{
		if (is_array($object)) {
			return $object;
		}
		return ['object' => $object];
	}

	/**
	 * 100 Debug level log
	 * @param string $log Message to log
	 * @param array|object|string $object Data to log
	 * @param int $stacktrace_skip Number of lines of the stacktrace to skip
	 * @return string Log ID
	 */
	public static function debug(string $log, array|object|string $object = [], int $stacktrace_skip = 0): string
	{
		$logObj = Log::getInstance();
		$prelog = $logObj->preLog(Level::Debug, $stacktrace_skip);

		$logObj->log->debug($log, array_merge($prelog, self::normalize_object($object)));

		return $prelog['log_id'];
	}

	/**
	 * 200 Info level log
	 * @param string $log Message to log
	 * @param array|object|string $object Data to log
	 * @param int $stacktrace_skip Number of lines of the stacktrace to skip
	 * @return string Log ID
	 */
	public static function info(string $log, array|object|string $object = [], int $stacktrace_skip = 0): string
	{
		$logObj = Log::getInstance();
		$prelog = $logObj->preLog(Level::Info, $stacktrace_skip);

		$logObj->log->info($log, array_merge($prelog, self::normalize_object($object)));

		return $prelog['log_id'];
	}

	/**
	 * 250 Notice level log
	 * @param string $log Message to log
	 * @param array|object|string $object Data to log
	 * @param int $stacktrace_skip Number of lines of the stacktrace to skip
	 * @return string Log ID
	 */
	public static function notice(string $log, array|object|string $object = [], int $stacktrace_skip = 0): string
	{
		$logObj = Log::getInstance();
		$prelog = $logObj->preLog(Level::Notice, $stacktrace_skip);

		$logObj->log->notice($log, array_merge($prelog, self::normalize_object($object)));

		return $prelog['log_id'];
	}

	/**
	 * 300 Warning level log
	 * @param string $log Message to log
	 * @param array|object|string $object Data to log
	 * @param int $stacktrace_skip Number of lines of the stacktrace to skip
	 * @return string Log ID
	 */
	public static function warning(string $log, array|object|string $object = [], int $stacktrace_skip = 0): string
	{
		$logObj = Log::getInstance();
		$prelog = $logObj->preLog(Level::Warning, $stacktrace_skip);

		$logObj->log->warning($log, array_merge($prelog, self::normalize_object($object)));

		return $prelog['log_id'];
	}

	/**
	 * @deprecated Use Log::warning() instead
	 * @param string $log
	 * @param array|object|string $object
	 * @return string
	 */
	public static function warn(string $log, array|object|string $object = []): string
	{
		return self::warning($log, $object);
	}

	/**
	 * 400 Error level log
	 * @param string $log Message to log
	 * @param array|object|string $object Data to log
	 * @return string Log ID
	 */
	public static function error(string $log, array|object|string $object = []): string
	{
		$logObj = Log::getInstance();
		$prelog = $logObj->preLog(Level::Error);

		$logObj->log->error($log, array_merge($prelog, self::normalize_object($object)));

		return $prelog['log_id'];
	}

	/**
	 * 500 Critical level log
	 * @param string $log Message to log
	 * @param array|object|string $object Data to log
	 * @return string Log ID
	 */
	public static function critical(string $log, array|object|string $object = []): string
	{
		$logObj = Log::getInstance();
		$prelog = $logObj->preLog(Level::Critical);

		$logObj->log->critical($log, array_merge($prelog, self::normalize_object($object)));

		return $prelog['log_id'];
	}

	/**
	 * 550 Alert level log
	 * @param string $log Message to log
	 * @param array|object|string $object Data to log
	 * @return string Log ID
	 */
	public static function alert(string $log, array|object|string $object = []): string
	{
		$logObj = Log::getInstance();
		$prelog = $logObj->preLog(Level::Alert);

		$logObj->log->alert($log, array_merge($prelog, self::normalize_object($object)));

		return $prelog['log_id'];
	}

	/**
	 * 600 Emergency level log
	 * @param string $log Message to log
	 * @param array|object|string $object Data to log
	 * @return string Log ID
	 */
	public static function emergency(string $log, array|object|string $object = []): string
	{
		$logObj = Log::getInstance();
		$prelog = $logObj->preLog(Level::Emergency);

		$logObj->log->emergency($log, array_merge($prelog, self::normalize_object($object)));

		return $prelog['log_id'];
	}
}
