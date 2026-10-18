<?php

namespace PSP\Util;

use Random\Engine\Mt19937;
use Random\Engine\Secure;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

class Crypt
{
	//TODO: Encrypt/Decrypt using PEM file
	
	/**
	 * Generate and return a GUID v4 string. If data is null, returned guid will be random and secure.
	 * @param string $data Optional binary string of length 16 to format as GUID. This leaves it up to the caller to generate the random string.
	 * @return string GUID v4 formatted string without surrounding braces
	 * @throws \Exception If $data is incorrect length or cannot generate random value
	 */
	public static function guid($data = null, $secure = true)
	{
		if (!$data) {
			$rand = new Randomizer(
				$secure ? new Secure() : new Xoshiro256StarStar(random_int(0, PHP_INT_MAX))
			);
			$data = $rand->getBytes(16);
		}
		if (strlen($data) !== 16) {
			throw new \Exception('Could not generate GUID');
		}

		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	/**
	 * Generate random alpha-numeric string of specified length
	 * @param int $length
	 * @param bool $user_friendly Removes characters that are easily mixed up by humans
	 * @return string
	 */
	public static function rand_alphanumeric($length, $user_friendly = false)
	{
		if ($user_friendly) {
			$chars = '23456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
		}
		else {
			$chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		}

		return self::rand_string($chars, $length);
	}

	/**
	 * Generate a good random integer
	 * @param int $min Minumim value
	 * @param int $max Maximum value
	 * @return int Integer within range
	 */
	public static function rand_int($min, $max)
	{
		return random_int($min, $max);
	}

	/**
	 * Generate random string of specified length based off the given string of characters
	 * @param string $characters List of characters allowed to be used
	 * @param int $length
	 * @return string
	 */
	public static function rand_string($characters, $length)
	{
		$string = '';
		$max = strlen($characters) - 1;
		for ($p = 0; $p < $length; $p++) {
			$string .= $characters[random_int(0, $max)];
		}
		return $string;
	}

	/**
	 * Get a random enum value
	 * @param class-string<\BackedEnum> $enum_class_name Example: Status::class
	 * @return mixed enum value. NOT enum itself
	 */
	public static function rand_enum($enum_class_name)
	{
		$count = count($enum_class_name::cases()) - 1;

		return $enum_class_name::cases()[Crypt::rand_int(0, $count)]->value;
	}
}
