<?php

namespace PSP\Exceptions;

class HTTPException extends \Exception{
	
	private $log_id;
	private $http_code;
	private $object;
	
	/**
	 * PSP HTTP exception
	 * @param string $message
	 * @param int $http_code
	 * @param string $log_id
	 * @param mixed $object Extra object to pass with error. Eg. Extra information
	 * @param int $status_code If different from http_code
	 * @param \Throwable $previous
	 */
	public function __construct($message = "", $http_code = 0, $log_id = null, $status_code=null, $object = null, \Throwable $previous = null) {
		$this->log_id = $log_id;
		$this->http_code = $http_code;
		$this->object = $object;
		
		parent::__construct($message,
			!is_null($status_code) ? $status_code : $http_code,
			$previous);
	}
	
	/**
	 * Return log ID for this exception
	 * @return string
	 */
	public function getLogID(){
		return $this->log_id;
	}
	
	/**
	 * Return HTTP Status code for this exception
	 * @return int
	 */
	public function getHTTPCode(){
		return $this->http_code;
	}
	
	/**
	 * Return additional data object saved with error
	 * @return mixed Additional data object saved with error
	 */
	public function getObject(){
		return $this->object;
	}
	
}