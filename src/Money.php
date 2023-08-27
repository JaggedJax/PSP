<?php

namespace PSP;

class Money{
	
	/**
	 * Take a number or numerical string and re-format as proper parsable string (to prevent rounding errors)
	 * Supports US and UK formats. Designed for money but will work with other formats as well.
	 * @param mixed $amount
	 * @param int $decimal_places
	 * @param int $round_mode
	 * @return string
	 */
	public static function to_string($amount, $decimal_places=2, $round_mode=PHP_ROUND_HALF_UP){
		if(empty($amount)){
			$amount = 0;
		}
		else if(is_object($amount)){
			$amount = (string)$amount;
		}
		if(is_string($amount)){
			$amount = trim($amount, "\$£€¥ \t\n\r\0\x0B");
			// Strip thousands separator
			$comma = 0+strrpos($amount, ',');
			$num_commas = substr_count($amount, ',');
			$period = 0+strrpos($amount, '.');
			$num_periods = substr_count($amount, '.');
			$len = strlen($amount);
			// Detect and strip thousands separator
			if($period || $comma){
				if($comma && ($num_commas > 1 || $period > $comma || (!$period && ($len - $comma -1) == 3))){
					$amount = str_replace(',','',$amount);
				}
				else if($period && ($num_periods > 1 || $comma > $period || (!$comma && ($len - $period -1) == 3))){
					$amount = str_replace('.','',$amount);
				}
			}
			$amount = floatval($amount);
		}
		return number_format(round($amount, $decimal_places), $decimal_places, '.', '');
	}
	
}