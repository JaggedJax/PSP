<?php

namespace PSP\Util;

class Math
{
	/**
	 * Convert weight units
	 * @param string $from One of lb, oz, g, kg
	 * @param string $to One of lb, oz, g, kg
	 * @param float $weight
	 * @param int $decimal_digit Num decimal digits to round to
	 * @return float
	 */
	public static function convert_weights(string $from, string $to, float $weight, int $decimal_digit = 4): float
	{
		$result = 0;
		
		$lb = ($from === 'lb') ? $weight : 0;
		$oz = ($from === 'oz') ? $weight : 0;
		$g = ($from === 'g') ? $weight : 0;
		$kg = ($from === 'kg') ? $weight : 0;

		// Convert to lbs or oz
		if ($to == 'lb' || $to == 'oz') {
			$result = $lb
				+ ($oz / 16)
				+ ($g * 0.00220462)
				+ ($kg * 2.20462);
			if ($to == 'oz') {
				$result = $result * 16;
			}
		}
		
		// Convert to g or kg
		if ($to == 'kg' || $to == 'g') {
			$result = $kg
				+ ($g / 1000)
				+ ($oz * 0.0283495)
				+ ($lb / 2.20462);
			if ($to == 'g') {
				$result = $result * 1000;
			}
		}
		
		if (empty($result)) {
			$result = 0;
		}
		
		return round($result, $decimal_digit);
	}

}
