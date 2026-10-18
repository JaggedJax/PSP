<?php

namespace PSP\Cache;

use PSP\Db\DB;
use PSP\Log\Log;
use PSP\Util\Strings;

class DBCache
{
	/** @var list<string> Allowed cache levels. Add app levels with add_cache_levels() */
	private static array $cache_levels = ['Global'];
	public const array DATA_TYPES = ['json','string'];
	
	/* Call fallback function if cached data missing or expired */
	public const int DEFAULT_CACHE = 0;
	/* Return cached data even if expired. Call fallback if no data is cached. */
	public const int INCLUDE_EXPIRED = 1;
	/* Always call the fallback function to get the freshest data */
	public const int FORCE_REFRESH = 2;
	/* Only return cached data. Do not call the fallback function */
	public const int CACHED_ONLY = 4;
	/* Do not cache the result of the fallback function */
	public const int NO_SAVE = 8;
	
	/**
	 * Add app-specific cache levels. EG: Customer
	 * @param string ...$levels
	 */
	public static function add_cache_levels(string ...$levels): void
	{
		self::$cache_levels = array_values(array_unique(array_merge(self::$cache_levels, $levels)));
	}

	/**
	 * Get data, Fallback to reload data if needed
	 * @param string $cache_level Global or a level added with add_cache_levels()
	 * @param int $pk cache_level pk. 0 for Global level
	 * @param string $cache_name
	 * @param array{callable:callable, args:mixed[]} $fallback_function Must pass a callable and list of args to pass to it
	 * @param int $cache_handling DEFAULT_CACHE, INCLUDE_EXPIRED, FORCE_REFRESH, CACHED_ONLY (Do not perform fallback function), NO_SAVE (Do not save result to cache)
	 * @return DBCacheResult|null
	 */
	public static function get(string $cache_level, int $pk, string $cache_name, array $fallback_function, int $cache_handling = DBCache::DEFAULT_CACHE): ?DBCacheResult
	{
		// Force PK 0 for Global level
		if ($cache_level == 'Global') {
			$pk = 0;
		}
		
		if (!in_array($cache_level, self::$cache_levels) || empty($cache_name)) {
			return null;
		}
		
		// Detect invalid cache pk. Raise warning and force cache refresh without saving the result
		if ($cache_level != 'Global' && $pk == 0) {
			trigger_error("Call to DBCache::get on invalid $cache_level pk: $pk", E_USER_WARNING);
			$cache_handling = self::FORCE_REFRESH | self::NO_SAVE;
		}
		
		// Check for cached data
		$result = ($cache_handling & self::FORCE_REFRESH) ? null : self::load($cache_level, $pk, $cache_name, boolval($cache_handling & self::INCLUDE_EXPIRED));
		
		// If no cached data, perform fallback function to load data
		if (
			// Load if no data
			((empty($result) && !($cache_handling & self::CACHED_ONLY))
				// OR if result is expired
				|| (($cache_handling & self::INCLUDE_EXPIRED) && !empty($result) && $result->expired))
		) {
			// Fix improper formatting of $fallback_function args
			if (!empty($fallback_function['args']) && !is_array($fallback_function['args'])) {
				$fallback_function['args'] = [$fallback_function['args']];
			}
			
			try {
				/** @var DBCacheResult $callable_result */
				$callable_result = call_user_func_array($fallback_function['callable'], $fallback_function['args'] ?? []);
			}
			catch (\Exception $e) {
				Log::warning("DBCache: failed call_user_func_array (".Strings::callable_as_string($fallback_function['callable']).") for {$cache_level}_pk_{$pk} with cache_name $cache_name : " . $e->getMessage());
				return null;
			}
			if ($callable_result instanceof DBCacheResult) {
				$result = $callable_result;
				$result->set_last_updated(date('Y-m-d H:i:s'));
				$result->set_expiration_date(date('Y-m-d H:i:s', time() + ($callable_result->cache_hours * 3600)));
				
				if ($cache_handling & self::NO_SAVE) {
					Log::info("DBCache: NO_SAVE requested for {$cache_level}_pk_{$pk} with cache_name $cache_name");
				}
				elseif (!self::save($cache_level, $pk, $cache_name, $callable_result)) {
					Log::warning("DBCache: Failed saving data for {$cache_level}_pk_{$pk} with cache_name $cache_name");
				}
			}
			elseif ($callable_result === null) {
				if (empty($result)) {
					// Log lookup failure only if we don't already have the cache loaded
					$msg = "DBCache: NULL response returned from call_user_func_array (" . Strings::callable_as_string($fallback_function['callable']) . ") for {$cache_level}_pk_{$pk} with cache_name $cache_name";
					Log::warning($msg);
				}
			}
			else {
				$msg = "DBCache: Invalid response returned from call_user_func_array (".Strings::callable_as_string($fallback_function['callable']).") for {$cache_level}_pk_{$pk} with cache_name $cache_name";
				lOG::Error($msg);
				trigger_error($msg, E_USER_WARNING);
			}
		}
		
		return $result;
	}
	
	/**
	 * Save new entry into Data Cache without performing any lookup
	 * @param string $cache_level Global or a level added with add_cache_levels()
	 * @param int $pk
	 * @param string $cache_name
	 * @param DBCacheResult $cache_object Data to cache
	 * @return bool Success
	 */
	public static function manual_save(string $cache_level, int $pk, string $cache_name, DBCacheResult $cache_object): bool
	{
		return self::save($cache_level, $pk, $cache_name, $cache_object);
	}
	
	/**
	 * Get cached data entry
	 * @param string $cache_level Global or a level added with add_cache_levels()
	 * @param int $pk
	 * @param string $cache_name
	 * @param bool $include_expired Should result be returned if it's expired?
	 * @return DBCacheResult|null
	 */
	private static function load(string $cache_level, int $pk, string $cache_name, bool $include_expired = false): ?DBCacheResult
	{
		if (!in_array($cache_level, self::$cache_levels) || empty($cache_name)) {
			return null;
		}
		
		$result = DB::instance()->select(
			'data_cache',
			[
				'cache_level' => $cache_level,
				'cache_level_pk' => $pk,
				'cache_name' => $cache_name,
			],
			['data', 'data_type', 'last_updated', 'expiration_date'],
			limit:1
		);
		if (empty($result)) {
			return null;
		}

		$time_left_seconds = strtotime($result['expiration_date']) - time();
		
		$cache = new DBCacheResult($result['data'], intval(ceil($time_left_seconds / 3600)), $result['data_type']);
		$cache->expired = $time_left_seconds < 0;
		
		if ($cache->expired && !$include_expired) {
			return null;
		}
		
		$cache->set_expiration_date($result['expiration_date']);
		$cache->set_last_updated($result['last_updated']);
		
		return $cache;
	}
	
	/**
	 * Save new entry into Data Cache
	 * @param string $cache_level Global or a level added with add_cache_levels()
	 * @param int $pk
	 * @param string $cache_name
	 * @param DBCacheResult $cache_object Data to cache
	 * @return bool Success
	 */
	private static function save(string $cache_level, int $pk, string $cache_name, DBCacheResult $cache_object): bool
	{
		if (!in_array($cache_level, self::$cache_levels)) {
			Log::warning("Invalid cache level: {$cache_level}");
			return false;
		}
		if (!in_array($cache_object->data_type, self::DATA_TYPES)) {
			Log::warning("Invalid Data Type: {$cache_object->data_type}");
			return false;
		}
		if (empty($cache_name) || empty($cache_object->cache_hours)) {
			Log::warning("Missing required fields: pk, cache_name, or cache_hours");
			return false;
		}

		// Format data for storage
		$data = DBCacheResult::encode_data($cache_object->get_data(false), $cache_object->data_type);
		
		$result = DB::instance()->insert(
			'data_cache',
			[
				'cache_level' => $cache_level,
				'cache_level_pk' => $pk,
				'cache_name' => $cache_name,
				'data' => $data,
				'data_type' => $cache_object->data_type,
				'expiration_date' => date('Y-m-d H:i:s', time() + ($cache_object->cache_hours * 3600))
			],
			['data', 'data_type', 'expiration_date']
		);
		
		if (!$result) {
			Log::error("Cache save failed ($result): ". DB::instance()->get_error());
		}
		
		return (bool)$result;
	}

}
