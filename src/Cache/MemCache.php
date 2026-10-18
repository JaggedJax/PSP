<?php

namespace PSP\Cache;

use PSP\App\Globals;

/**
 * Simple Memory Cache
 */
class MemCache
{
	/**
	 * Connect to a Memcached server. Without a connection, every MemCache call is a no-op
	 * @param string $server Host name or IP
	 * @param int $port
	 * @return bool Success
	 */
	public static function connect(string $server, int $port = 11211): bool
	{
		if (!class_exists(\Memcached::class)) {
			return false;
		}
		$memcached = new \Memcached();
		if (!$memcached->addServer($server, $port)) {
			return false;
		}
		Globals::instance()->memcached = $memcached;
		return true;
	}

	/**
	 * Cache data
	 *
	 * @param string $name Unique cache name. Be sure to your name doesn't mix across accounts!!!
	 * @param mixed $data Stuff to save
	 * @param int $seconds Number of seconds to cache for
	 * @retrun bool Success
	*/
	public static function save(string $name, mixed $data, int $seconds = 600): bool
	{
		$result =  Globals::instance()->memcached?->set($name, $data, $seconds);
		if ($result === null) {
			$result = false;
		}
		
		return $result;
	}
	
	
	/**
	 * Read Cache from MemCache. Return FALSE if MemCache is not enabled or entry not found!
	 *
	 * @param string $name
	 * @return mixed FALSE if entry not found
	 */
	public static function get(string $name): mixed
	{
		if (empty(Globals::instance()->memcached)) {
			return false;
		}
		
		return Globals::instance()->memcached->get($name);
	}
	
	/**
	 * Remove record(s) from MemCache.
	 *
	 * @param string|string[] $name Cache key or array of keys to delete
	 * @return bool|int|array{string,bool|int}
	 */
	public static function delete(string|array $name): bool|int|array
	{
		if (is_array($name)) {
			$result = Globals::instance()->memcached?->deleteMulti($name);
		}
		else {
			$result = Globals::instance()->memcached?->delete($name);
		}
		if (empty($result)) {
			$result = false;
		}
		return $result;
	}
	
	/**
	 * Increment the value of an item in the cache.
	 *
	 * @param string $name
	 * @param int $offset
	 * @return int|bool New value or FALSE on failure
	 */
	public static function increment(string $name, int $offset = 1): int|bool
	{
		if (empty(Globals::instance()->memcached)) {
			return false;
		}
		return Globals::instance()->memcached->increment($name, $offset);
	}

}
