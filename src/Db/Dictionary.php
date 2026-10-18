<?php

namespace PSP\Db;

use PSP\Util\Crypt;

abstract class Dictionary
{
	/**
	 * Return List of values from dictionary
	 * @return mixed|mixed[]
	 */
	abstract public static function get_values(mixed $selector = null): mixed;

	/**
	 * Get one random value from dictionary
	 * @return mixed Null if the dictionary is empty
	 */
	public static function get_one(): mixed
	{
		$values = array_values((array)static::get_values());
		if (empty($values)) {
			return null;
		}

		return $values[Crypt::rand_int(0, count($values) - 1)];
	}

}
