<?php

namespace PSP;

class Crypt{
	
	//TODO: Encrypt/Decrypt using PEM file
	
	/**
	 * Generate and return a GUID v4 string. If data is null, returned guid will be random and secure.
	 * @param string $data Optional binary string of length 16 to format as GUID. This leaves it up to the caller to generate the random string.
	 * @return string GUID v4 formatted string without surrounding braces
	 */
	public static function guidv4($data=null){
		if(!$data){
			$data = openssl_random_pseudo_bytes(16);
		}
		assert(strlen($data) == 16);
		
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
	public static function rand_alphanumeric($length, $user_friendly=false){
		if($user_friendly){
			$chars = '23456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
		}
		else{
			$chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		}
		
		return self::rand_string('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $length);
	}
	
	/**
	 * Generate a good random integer
	 * @param int $min Minumim value
	 * @param int $max Maximum value
	 * @return int Integer within range
	 */
	public static function rand_int($min, $max){
		mt_srand(self::make_seed());
		return mt_rand($min, $max);
	}
	
	/**
	 * Generate random string of specified length based off the given string of characters
	 * @param string $characters List of characters allowed to be used
	 * @param int $length
	 * @return string
	 */
	public static function rand_string($characters, $length){
		$string = '';
		mt_srand(self::make_seed());
		for ($p = 0; $p < $length; $p++) {
			$string .= $characters[mt_rand(0, strlen($characters)-1)];
		}
		return $string;
	}
	
	/**
	 * Generate a seed based off the time
	 */
	private static function make_seed(){
		list($usec, $sec) = explode(' ', microtime());
		return (float) $sec + ((float) $usec * 100000);
	}
	
}