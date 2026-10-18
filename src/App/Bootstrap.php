<?php

namespace PSP\App;

use OpenTelemetry\API\Globals as OtelGlobals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use PSP\Cache\DBCache;
use PSP\Cache\MemCache;
use PSP\Db\DBTable;
use PSP\Db\History;
use PSP\Log\Log;
use PSP\Log\Telemetry;
use PSP\Process\Lock;

/**
 * One-call setup for every entry point (web and CLI). Call it right after vendor/autoload.php
 */
class Bootstrap
{
	/** Fatal error types that end the script */
	private const array FATAL_ERRORS = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR];

	/** Characters allowed in a CSP nonce from a request header (base64 and base64url) */
	private const string NONCE_PATTERN = '/^[A-Za-z0-9+\/=_-]{8,128}$/';

	private static ?SpanInterface $root_span = null;
	private static ?ScopeInterface $root_scope = null;

	/**
	 * Set up the app. All options are optional
	 * @param array{
	 *     timezone?: string,
	 *     object_namespace?: string,
	 *     log_fields?: list<string>,
	 *     trace_prefix?: string,
	 *     tracer_name?: string,
	 *     trusted_ip_headers?: list<string>,
	 *     cache_levels?: list<string>,
	 *     changed_by?: callable(): (string|int|null),
	 *     memcached?: array{server?: string, port?: int|string}|null,
	 *     session_lifetime?: int,
	 *     nonce_header?: string,
	 *     csp?: array<string, list<string>>,
	 *     referrer_policy?: string,
	 *     hsts_max_age?: int,
	 *     shutdown_handler?: bool,
	 * } $options See README for each option
	 */
	public static function init(array $options = []): void
	{
		$globals = Globals::instance();

		// date()/DateTime and the MySQL session time_zone (see DB::connect()) all use this
		if (!empty($options['timezone'])) {
			date_default_timezone_set($options['timezone']);
		}

		self::apply_settings($options);

		if (!$globals->is_unit_test) {
			$memcached = $options['memcached'] ?? Config::get('psp')['memcached'] ?? null;
			if (!empty($memcached['server'])) {
				MemCache::connect($memcached['server'], (int)($memcached['port'] ?? 11211));
			}
		}

		if (!$globals->is_unit_test && $globals->environment !== 'local') {
			self::start_root_span($options['tracer_name'] ?? 'psp');
			Telemetry::tag('environment', $globals->environment);
		}

		if (!$globals->is_cli) {
			self::setup_web($options);
		}

		if (!$globals->is_unit_test && ($options['shutdown_handler'] ?? true)) {
			self::register_shutdown();
		}
		// Registered last so it runs after shutdown(), and the shutdown logs are in the trace
		if (self::$root_span !== null) {
			register_shutdown_function([self::class, 'end_root_span']);
		}
	}

	/**
	 * Apply the app settings of the shared helpers. Called by init()
	 * @param array<string, mixed> $options Same as init()
	 */
	public static function apply_settings(array $options): void
	{
		if (isset($options['object_namespace'])) {
			$namespace = trim($options['object_namespace'], '\\');
			DBTable::$_object_namespace = $namespace !== '' ? $namespace . '\\' : '';
		}
		if (isset($options['trace_prefix'])) {
			Telemetry::$attribute_prefix = $options['trace_prefix'];
		}
		if (!empty($options['log_fields'])) {
			Log::add_fields($options['log_fields']);
		}
		if (isset($options['trusted_ip_headers'])) {
			Server::trust_ip_headers(...$options['trusted_ip_headers']);
		}
		if (!empty($options['cache_levels'])) {
			DBCache::add_cache_levels(...$options['cache_levels']);
		}
		if (isset($options['changed_by'])) {
			History::set_changed_by_resolver($options['changed_by']);
		}
	}

	/**
	 * Build the web response headers from the init() options. The nonce placeholder {nonce} in CSP sources is replaced
	 * @param array<string, mixed> $options Same as init()
	 * @param string $nonce CSP nonce for this request
	 * @param string $environment local, dev, stage or prod
	 * @return list<string> Header lines
	 */
	public static function web_headers(array $options, string $nonce, string $environment): array
	{
		$headers = [];
		if (!empty($options['csp'])) {
			$csp = '';
			foreach ($options['csp'] as $directive => $sources) {
				$csp .= $directive . ' ' . str_replace('{nonce}', $nonce, implode(' ', $sources)) . '; ';
			}
			$headers[] = 'Content-Security-Policy: ' . trim($csp);
		}
		// Cross-origin requests (CDN files, analytics beacons) only see our origin, not the full path
		$headers[] = 'Referrer-Policy: ' . ($options['referrer_policy'] ?? 'strict-origin-when-cross-origin');
		if (!empty($options['hsts_max_age']) && !in_array($environment, ['dev', 'local'], true)) {
			$headers[] = 'Strict-Transport-Security: max-age=' . (int)$options['hsts_max_age'];
		}
		return $headers;
	}

	/**
	 * End the process cleanly: release CLI locks and log a fatal error. Runs at shutdown, and on SIGTERM/SIGINT it then exits
	 * @param int|string $signo Signal number, or "exit" for a normal shutdown
	 */
	public static function shutdown(int|string $signo = 'exit'): void
	{
		$globals = Globals::instance();
		if (!$globals->in_shutdown) {
			$globals->in_shutdown = true;
			Log::debug('DB Queries used: ' . $globals->num_db_calls);
			self::release_and_report();
		}

		// A signal handler that returns lets the script keep running after its locks are released
		if (is_int($signo)) {
			exit(128 + $signo);
		}
	}

	/**
	 * Release the locks of a CLI script and log a fatal error. Called once by shutdown()
	 */
	private static function release_and_report(): void
	{
		$globals = Globals::instance();
		if ($globals->is_cli) {
			try {
				(new Lock('shutdown', Lock::Global))->release_all();
			}
			catch (\Throwable) {
			}
		}

		$error = error_get_last();
		if (!empty($error) && in_array($error['type'], self::FATAL_ERRORS, true)) {
			$log_id = Log::emergency("Script halted in {$error['file']}:{$error['line']} with Error: {$error['message']}");
			if (!$globals->is_cli) {
				@header("HTTP/1.1 500 Internal Server Error - Log ID: $log_id");
			}
		}
	}

	/**
	 * Start the OpenTelemetry root span for this request or job. The SDK is configured with OTEL_* env vars and is a no-op when off
	 * @param string $tracer_name
	 */
	private static function start_root_span(string $tracer_name): void
	{
		$globals = Globals::instance();
		$builder = OtelGlobals::tracerProvider()->getTracer($tracer_name)->spanBuilder(
			$globals->is_cli
				? 'job ' . $globals->process_name
				: ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . self::request_path()
		);
		if ($globals->is_cli) {
			$builder
				->setSpanKind(SpanKind::KIND_INTERNAL)
				->setAttribute('process.command_args', array_slice($GLOBALS['argv'] ?? [], 1))
				->setAttribute(Telemetry::$attribute_prefix . '.process_name', $globals->process_name);
			// A parent process can pass its trace context in the standard TRACEPARENT env var. Otherwise start a new trace
			$traceparent = getenv('TRACEPARENT');
			$builder->setParent(!empty($traceparent) ? TraceContextPropagator::getInstance()->extract(['traceparent' => $traceparent]) : false);
		}
		else {
			$builder
				->setParent(TraceContextPropagator::getInstance()->extract(function_exists('getallheaders') ? (getallheaders() ?: []) : []))
				->setSpanKind(SpanKind::KIND_SERVER)
				->setAttribute('http.request.method', $_SERVER['REQUEST_METHOD'] ?? 'GET')
				->setAttribute('url.path', self::request_path())
				->setAttribute('server.address', $_SERVER['HTTP_HOST'] ?? '');
		}
		self::$root_span = $builder->startSpan();
		self::$root_scope = self::$root_span->activate();
		Telemetry::set_root_span(self::$root_span);
	}

	/**
	 * Web request setup: process name, session lifetime, CSP nonce and response headers
	 * @param array<string, mixed> $options Same as init()
	 */
	private static function setup_web(array $options): void
	{
		$globals = Globals::instance();
		$globals->process_name = 'web';

		if (!empty($options['session_lifetime'])) {
			ini_set('session.gc_maxlifetime', (string)(int)$options['session_lifetime']);
			ini_set('session.cookie_lifetime', '0');
		}

		// HTMX partial updates send the nonce of the first page load, so their scripts match the page CSP
		if (!empty($options['nonce_header']) && self::is_valid_nonce($_SERVER[$options['nonce_header']] ?? null)) {
			$globals->nonce = (string)$_SERVER[$options['nonce_header']];
		}

		foreach (self::web_headers($options, $globals->nonce, $globals->environment) as $header) {
			header($header);
		}
	}

	/**
	 * Is this a safe CSP nonce from a request header? It goes into the CSP header and HTML attributes, so only base64 characters are allowed
	 * @param mixed $nonce
	 * @return bool
	 */
	public static function is_valid_nonce(mixed $nonce): bool
	{
		return is_string($nonce) && preg_match(self::NONCE_PATTERN, $nonce) === 1;
	}

	/**
	 * Path of the web request without the query string
	 * @return string
	 */
	private static function request_path(): string
	{
		$path = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
		return $path !== false ? $path : '/';
	}

	/**
	 * Register shutdown() for normal exit and SIGTERM/SIGINT
	 */
	private static function register_shutdown(): void
	{
		// pcntl is usually only in the CLI SAPI
		if (function_exists('pcntl_async_signals')) {
			pcntl_async_signals(true);
			pcntl_signal(SIGTERM, [self::class, 'shutdown']);
			pcntl_signal(SIGINT, [self::class, 'shutdown']);
		}
		register_shutdown_function([self::class, 'shutdown'], 'exit');
	}

	/**
	 * End the root span with the result of the request or job, then flush it. Registered by init()
	 */
	public static function end_root_span(): void
	{
		$span = self::$root_span;
		if ($span === null) {
			return;
		}
		if (Globals::instance()->is_cli) {
			$error = error_get_last();
			if (!empty($error) && in_array($error['type'], self::FATAL_ERRORS, true)) {
				$span->setStatus(StatusCode::STATUS_ERROR, $error['message']);
			}
		}
		else {
			$status = http_response_code() ?: 200;
			$span->setAttribute('http.response.status_code', $status);
			if ($status >= 500) {
				$span->setStatus(StatusCode::STATUS_ERROR);
			}
		}
		self::$root_scope?->detach();
		$span->end();
		self::$root_span = null;
		self::$root_scope = null;

		// The SDK's own shutdown hook can run before this one, so flush here
		$provider = OtelGlobals::tracerProvider();
		if (method_exists($provider, 'forceFlush')) {
			$provider->forceFlush();
		}
	}
}
