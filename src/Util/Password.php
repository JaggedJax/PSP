<?php

namespace PSP\Util;

use PSP\App\Config;
use PSP\Cache\DBCache;
use PSP\Cache\DBCacheResult;
use PSP\Http\Http;
use PSP\Log\Log;

/**
 * Standardized password rules and HaveIBeenPwned breach checking, shared by every login and
 * user-creation flow.
 *
 * Uses the HIBP "Pwned Passwords" k-anonymity range API: only the first 5 characters of the
 * password's SHA-1 hash are ever sent, so the plaintext password (and even its full hash) never
 * leaves our server. Range responses are cached via DBCache so repeat lookups for a common
 * hash prefix don't re-hit the API.
 */
class Password
{
	public const int MIN_LENGTH = 7;

	private const string RANGE_API_BASE_URL = 'https://api.pwnedpasswords.com';
	private const int CACHE_HOURS = 24 * 7; // Breach counts for a given hash prefix rarely change

	/**
	 * Validate a new or changed password: minimum length, and not found in a known breach.
	 * Call this from every signup / user-creation / password-change flow before saving.
	 * @param string $password
	 * @return array{valid:bool, message:?string} message is null when valid, otherwise a user-facing reason
	 */
	public static function validate_new(string $password): array
	{
		$password = trim($password);

		if (strlen($password) < self::MIN_LENGTH) {
			return [
				'valid' => false,
				'message' => 'Password must be at least '.self::MIN_LENGTH.' characters.',
			];
		}

		$breach_count = self::check_pwned($password);
		if (!empty($breach_count)) {
			return [
				'valid' => false,
				'message' => 'This password has appeared in '.number_format($breach_count).' known data breaches. Please choose a different password.',
			];
		}

		return ['valid' => true, 'message' => null];
	}

	/**
	 * Check a password used to successfully log in against HaveIBeenPwned, so the user can be
	 * alerted (not blocked - they're already in) if it's since shown up in a breach.
	 * @param string $password
	 * @return string|null Warning message to show the user, or null if not known to be breached (or the check could not be performed)
	 */
	public static function login_warning(string $password): ?string
	{
		$breach_count = self::check_pwned($password);
		if (empty($breach_count)) {
			return null;
		}

		return 'The password you just used has appeared in '.number_format($breach_count).' known data breaches. For your security, please change it as soon as possible.';
	}

	/**
	 * Look up a password against the HaveIBeenPwned Pwned Passwords range API using k-anonymity.
	 * Fails open: returns null (instead of throwing) if the check could not be completed, so an
	 * HIBP outage never blocks login/signup.
	 * @param string $password
	 * @return int|null Number of times the password has appeared in known breaches, 0 if clean, or null if the check could not be performed
	 */
	public static function check_pwned(string $password): ?int
	{
		$password = trim($password);
		if ($password === '') {
			return 0;
		}

		if ((Config::get('hibp')['enabled'] ?? true) === false) {
			return null;
		}

		$sha1 = strtoupper(sha1($password));
		$prefix = substr($sha1, 0, 5);
		$suffix = substr($sha1, 5);

		// The cache lookup itself hits the DB - guard it too, so a DB hiccup fails open
		// the same way an HIBP outage does, instead of taking down login/signup.
		try {
			$result = DBCache::get('Global', 0, "hibp_range_{$prefix}", [
				'callable' => [self::class, 'fetch_range'],
				'args' => [$prefix],
			]);
		}
		catch (\Throwable $e) {
			Log::warning('HaveIBeenPwned breach check failed', ['exception' => $e->getMessage()]);
			return null;
		}

		$ranges = $result?->get_data();
		if (!is_array($ranges)) {
			return null;
		}

		return $ranges[$suffix] ?? 0;
	}

	/**
	 * Fetch and parse the k-anonymity hash-suffix range for a SHA-1 prefix from HIBP.
	 * Called by DBCache::get() as the cache-miss fallback - not intended to be called directly.
	 * @param string $prefix First 5 characters of the uppercase SHA-1 password hash
	 * @return DBCacheResult|null Data is a [hash_suffix => breach_count] map. Null on failure (nothing gets cached)
	 */
	public static function fetch_range(string $prefix): ?DBCacheResult
	{
		try {
			$http = new Http(self::RANGE_API_BASE_URL);
			$http->custom_response_parser('trim');
			// Padding hides the true response size, per HIBP's recommended practice for this endpoint
			$response = $http->get("/range/{$prefix}", [], ['Add-Padding: true'], false);
		}
		catch (\Throwable $e) {
			Log::warning('HaveIBeenPwned range lookup failed', ['exception' => $e->getMessage(), 'prefix' => $prefix]);
			return null;
		}

		if (empty($response) || !is_string($response)) {
			return null;
		}

		$suffixes = [];
		foreach (preg_split('/\r\n|\r|\n/', $response) as $line) {
			[$hash_suffix, $count] = array_pad(explode(':', trim($line), 2), 2, null);
			if (!empty($hash_suffix)) {
				$suffixes[$hash_suffix] = (int)$count;
			}
		}

		return new DBCacheResult($suffixes, self::CACHE_HOURS, 'json');
	}
}
