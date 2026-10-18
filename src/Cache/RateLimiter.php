<?php

namespace PSP\Cache;

use PSP\App\Server;

/**
 * Rate limit actions per IP address with MemCache
 */
class RateLimiter
{
	/**
	 * Call this to ensure users don't abuse a specific segment of code. Response of TRUE means to allow access
	 * Without a MemCache connection there is no limit and this always returns TRUE
	 * TODO: Ensure MemCache works cross server
	 * @param string $rate_limit_type Name of what you want to rate limit. A separate limit will be kept for each type
	 * @param int $attemptsBeforeLock Number of requests allowed (every $window_minutes minutes) before block
	 * @param string|null $ip_address user IP Address (or other data to set limit on, like a PK). If not passed, IP will be detected automatically
	 * @param int $window_minutes Time before window resets and access is unblocked
	 * @return bool Allow access? If false is returned you should exit the script.
	 */
	public static function allow(string $rate_limit_type = 'general', int $attemptsBeforeLock = 7, string|null $ip_address = null, int $window_minutes = 15): bool
	{
		if (empty($ip_address)) {
			$ip_address = Server::get_ip() ?? 'NA';
		}
		
		// TODO: Check against banned IP list
		/*
		if(is_banned($ip_address)){
			usleep(500000); // Force a .5 second wait before responding
			return false;
		}
		*/
		
		$cache_key = 'rate_limit_'.$rate_limit_type.'_'.$ip_address;
		
		$request_rate = MemCache::get($cache_key);
		
		$window_seconds = 60 * $window_minutes; // Block time = X minutes
		
		$minute_limit = $attemptsBeforeLock; // Max Requests per X minutes
		if (!$request_rate) {
			$new_minute_throttle = $window_seconds / $minute_limit;
		}
		else {
			$last_api_diff = time() - $request_rate['last_request_time'];
			$new_minute_throttle = $request_rate['minute_throttle'] - $last_api_diff;
			$new_minute_throttle = max($new_minute_throttle, 0);
			$new_minute_throttle += $window_seconds / $minute_limit;
			
			// Return remaining hits to user in header
			//$minute_hits_remaining = floor( ( $minute - $new_minute_throttle ) * $minute_limit / $minute );
		}
		if ($new_minute_throttle > $window_seconds) {
			//$wait = ceil($new_minute_throttle - $minute);
			usleep(500000); // Force a .5 second wait before responding
			return false;
		}
		
		$request_rate['last_request_time'] = time();
		$request_rate['minute_throttle'] = $new_minute_throttle;
		MemCache::save($cache_key, $request_rate, $window_seconds);
		
		return true;
	}
}
