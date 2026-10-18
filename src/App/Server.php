<?php

namespace PSP\App;

/**
 * Information about the current server, process and request
 */
class Server
{
	/**
	 * Get a stack trace as an array of strings
	 * @param int $ignore_last_calls How many calls to leave off? 1 leaves off this function
	 * @param int $max_lines 0 for all lines
	 * @return list<string>
	 */
	public static function stack_trace($ignore_last_calls = 1, $max_lines = 0): array
	{
		$e = new \Exception();
		$trace = explode("\n", $e->getTraceAsString());
		// reverse array to make steps line up chronologically
		$trace = array_reverse($trace);
		array_shift($trace); // remove {main}
		for ($i = 0; $i < $ignore_last_calls; $i++) {
			array_pop($trace); // remove call to this method
		}

		// When limiting lines, reverse the order so we're getting the most recent
		if (!empty($max_lines)) {
			$trace = array_reverse($trace);
		}

		$length = !empty($max_lines) ? min(count($trace), $max_lines) : count($trace);
		$result = [];

		for ($i = 0; $i < $length; $i++) {
			$space_pos = strpos($trace[$i], ' ');
			$result[] = ($i + 1) . ')' . ($space_pos !== false ? substr($trace[$i], $space_pos) : ' ' . $trace[$i]);
		}

		// When limiting lines, unreverse the lines
		if (!empty($max_lines)) {
			$result = array_reverse($result);
		}

		return $result;
	}

	/**
	 * Get info about server
	 * @return array{hostname:string, ip:string}
	 */
	public static function server_info(): array
	{
		$hostname = gethostname();
		$ip = gethostbyname($hostname);
		
		return [
			'hostname' => $hostname ? strtolower($hostname) : $ip,
			'ip' => $ip,
		];
	}

	/** @var list<string> Server keys of proxy headers that hold the client IP, checked in order */
	private static array $ip_headers = [];

	/**
	 * Trust client IP headers set by a proxy in front of the app. Clients can send these headers too, so only add headers your proxy sets
	 * @param string ...$headers $_SERVER keys, checked in order. EG: HTTP_CF_CONNECTING_IP
	 */
	public static function trust_ip_headers(string ...$headers): void
	{
		self::$ip_headers = array_values($headers);
	}

	/**
	 * Get the connecting user's IP from a trusted proxy header, else REMOTE_ADDR. NULL if none or not a web request
	 * @return string|null
	 */
	public static function get_ip(): ?string
	{
		foreach (self::$ip_headers as $header) {
			if (!empty($_SERVER[$header])) {
				// X-Forwarded-For can hold a list: client, proxy1, proxy2
				return trim(explode(',', (string)$_SERVER[$header])[0]);
			}
		}
		return $_SERVER['REMOTE_ADDR'] ?? null;
	}
}
