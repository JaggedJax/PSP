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
	 * @return mixed
	 */
	public static function get_one(): mixed
	{
		$values = static::get_values();

		return $values[Crypt::rand_int(0, count($values) - 1)];
	}

}
