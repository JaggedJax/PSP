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

	/**
	 * Get the connecting user's IP. NULL if none or not a web request
	 * @return string|null
	 */
	public static function get_ip(): ?string
	{
		return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
	}
}
