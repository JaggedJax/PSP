<?php

namespace PSP\Cache;

class DataCacheResult
{
	private mixed $data;
	public int $cache_hours {
		get {
			return $this->cache_hours;
		}
	}
	public string $data_type {
		get {
			return $this->data_type;
		}
	}
	private string $last_updated;
	private string $expiration_date;
	public bool $expired = false {
		get {
			return $this->expired;
		}
		set {
			$this->expired = $value;
		}
	}
	
	/**
	 * Create new DataCacheResult object
	 * @param mixed $data Whatever data needs to be cached. Usually an array
	 * @param int $cache_hours # of hours to cache for
	 * @param string $data_type Data type: One of DataCache::DATA_TYPES
	 */
	public function __construct(mixed $data, int $cache_hours = 24, string $data_type = 'json')
	{
		// We don't auto-encode the data here. We'll lazy encode it in DataCache if/when we save it to the DB.
		// This avoids extra unnecessary encodes/decodes
		$this->data = $data;
		$this->cache_hours = $cache_hours;
		$this->data_type = $data_type;
	}
	
	/**
	 * Set Cache last updated date
	 * @param string $last_updated
	 * @return void
	 */
	public function set_last_updated(string $last_updated): void
	{
		$this->last_updated = $last_updated;
	}
	
	/**
	 * Set Cache expiration date
	 * @param string $expiration_date
	 * @return void
	 */
	public function set_expiration_date(string $expiration_date): void
	{
		$this->expiration_date = $expiration_date;
	}
	
	/**
	 * Cache last updated date
	 * @return string|null last_updated
	 */
	public function get_last_updated(): ?string
	{
		return $this->last_updated ?? null;
	}
	
	/**
	 * Cache expiration date
	 * @return string|null expiration_date
	 */
	public function get_expiration_date(): ?string
	{
		return $this->expiration_date ?? null;
	}
	
	/**
	 * Get cached data and ensure it's decoded
	 * @param bool $auto_decode Decode data, or return raw potentially encoded string
	 * @return mixed
	 */
	public function get_data(bool $auto_decode = true): mixed
	{
		return $auto_decode ? self::decode_data($this->data, $this->data_type) : $this->data;
	}
	
	/**
	 * Get cached data as an array
	 * @return array{data:mixed[]|null, data_type:string, last_updated:string|null, expiration_date:string|null, expired:bool}
	 */
	public function as_array(): array
	{
		return [
			'data' => isset($this->data) ? $this->get_data() : null,
			'data_type' => $this->data_type,
			'last_updated' => $this->last_updated ?? null,
			'expiration_date' => $this->expiration_date ?? null,
			'expired' => $this->expired,
		];
	}

	/**
	 * Decode data for use by caller
	 * @param string|mixed[] $data
	 * @param string $data_type One of DataCache::DATA_TYPES
	 * @return mixed Example, JSON will become an array
	 */
	private static function decode_data(mixed $data, string $data_type): mixed
	{
		// Format response data
		switch ($data_type) {
			case 'json':
				if (is_string($data)) {
					$temp = json_decode($data, true);
					// Prevent failed decode from wiping out string result
					if ($temp !== null) {
						$data = $temp;
					}
				}
				break;
			case 'string':
				// Nothing to do, already a string
				break;
		}

		return $data;
	}

	/**
	 * Encode data for inserting into DB
	 * @param mixed $data
	 * @param string $data_type One of DataCache::DATA_TYPES
	 * @return string
	 */
	public static function encode_data(mixed $data, string $data_type): string
	{
		// Format response data for inserting into DB string field
		switch ($data_type) {
			case 'json':
				if (is_array($data) || is_object($data)) {
					$data = json_encode($data);
				}
				break;
			case 'string':
				if (!is_string($data)) {
					$data = strval($data);
				}
				break;
		}

		return $data;
	}
}
