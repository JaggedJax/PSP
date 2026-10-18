<?php

namespace PSP\Log;

/**
 * Collects the Log IDs of the log lines written while one order is handled, so History entries can point to them.
 * Trails nest: each begin() needs one end()
 */
class LogTrail
{
	/** Most Log IDs saved on one History entry */
	public const int MAX_IDS = 30;

	/** @var list<list<string>> One list of Log IDs for each open trail */
	private static array $trails = [];

	/**
	 * Open a new trail. Log IDs added after this call go to the new trail
	 * @param list<string> $ids Log IDs the trail starts with. Example: the IDs of a file delivery that explains each order in the file
	 * @return void
	 */
	public static function begin(array $ids = []): void
	{
		self::$trails[] = $ids;
	}

	/**
	 * Add a Log ID to the open trail. Does nothing when no trail is open
	 * @param string $log_id Return value of a Log method
	 * @return string The same Log ID, so a call can wrap a Log call. Example: LogTrail::add(Log::info('...'))
	 */
	public static function add(string $log_id): string
	{
		if (!empty(self::$trails)) {
			self::$trails[array_key_last(self::$trails)][] = $log_id;
		}
		return $log_id;
	}

	/**
	 * Get the Log IDs of the open trail, oldest first
	 * @return list<string>
	 */
	public static function ids(): array
	{
		return empty(self::$trails) ? [] : self::$trails[array_key_last(self::$trails)];
	}

	/**
	 * Close the open trail
	 * @return list<string> Log IDs of the closed trail
	 */
	public static function end(): array
	{
		return array_pop(self::$trails) ?? [];
	}

	/**
	 * Close all trails. For tests
	 * @return void
	 */
	public static function reset(): void
	{
		self::$trails = [];
	}

	/**
	 * Make the stored text for a list of Log IDs. The newest IDs are kept when the list is too long
	 * @param list<string> $log_ids
	 * @return string|null Null when the list is empty
	 */
	public static function pack(array $log_ids): ?string
	{
		$log_ids = array_slice(array_values(array_unique($log_ids)), -self::MAX_IDS);
		return empty($log_ids) ? null : implode(',', $log_ids);
	}
}
