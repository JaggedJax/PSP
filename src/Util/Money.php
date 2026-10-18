<?php

namespace PSP\Util;

class Money
{
	/**
	 * Take a number or numerical string and re-format as proper parsable string (to prevent rounding errors)
	 * Supports US and UK formats. Designed for money but will work with other formats as well.
	 * @param mixed $amount
	 * @param int $decimal_places
	 * @param int $round_mode
	 * @return string
	 */
	public static function to_string(mixed $amount, int $decimal_places = 2, int $round_mode = PHP_ROUND_HALF_UP): string
	{
		if (empty($amount)) {
			$amount = 0;
		}
		elseif (is_object($amount)) {
			$amount = (string)$amount;
		}
		if (is_string($amount)) {
			$amount = trim($amount, "\$£€¥ \t\n\r\0\x0B");
			// Strip thousands separator
			$comma = 0 + strrpos($amount, ',');
			$num_commas = substr_count($amount, ',');
			$period = 0 + strrpos($amount, '.');
			$num_periods = substr_count($amount, '.');
			$len = strlen($amount);
			// Detect and strip thousands separator
			if ($period || $comma) {
				if ($comma && ($num_commas > 1 || $period > $comma || (!$period && ($len - $comma - 1) == 3))) {
					$amount = str_replace(',', '', $amount);
				}
				elseif ($period && ($num_periods > 1 || $comma > $period || (!$comma && ($len - $period - 1) == 3))) {
					$amount = str_replace('.', '', $amount);
				}
			}
			$amount = floatval($amount);
		}
		return number_format(round($amount, $decimal_places), $decimal_places, '.', '');
	}
	
	/**
	 * Calculate the tax rate as a decimal (4 Decimal places of precision)
	 * @param float|int $price Total taxable price. Example: sub_total - order_discount (+ shipping_handling) Only shipping_handling is taxable.
	 * @param float|int $tax_amt Dollar value of taxes
	 * @param string $tax_type inclusive or exclusive
	 * @return float Tax rate as decimal. Eg. 20% will return as: .2000 (4 Decimal places of precision)
	 */
	public static function get_tax_rate(float|int $price, float|int $tax_amt, string $tax_type = 'exclusive'): float
	{
		if ($tax_type == 'exclusive') {
			$tax_rate = ($price == 0) ? 0 : $tax_amt / $price;
		}
		else {
			$tax_rate = ($price - $tax_amt <= 0) ? 0 : $tax_amt / ($price - $tax_amt);
		}
		
		return round($tax_rate, 4);
	}
	
	/**
	 * Calculate the tax amount from the rate
	 * @param float|int $price Total taxable price
	 * @param float|int $rate Tax rate as a decimal
	 * @param string $tax_type inclusive or exclusive
	 * @return float
	 */
	public static function get_tax_amount(float|int $price, float|int $rate, string $tax_type = 'exclusive'): float
	{
		if ($tax_type == 'exclusive') {
			$tax_amount = $price * $rate;
		}
		else {
			$tax_amount = ($price * $rate) / (1 + $rate);
		}
		
		return round($tax_amount, 4);
	}
	
	/**
	 * Convert from a Tax Inclusive Price to a Tax Exclusive one
	 * @param float|int $inclusive_price
	 * @param float|int $tax_amt tax dollar amount if known
	 * @param float|int|null $tax_rate Pass tax percentage if known. For 20% pass .2
	 * @return float Tax Exclusive Price
	 */
	public static function inclusive_to_exclusive(float|int $inclusive_price, float|int $tax_amt, float|int|null $tax_rate = null): float
	{
		if (!empty($tax_amt)) {
			return $inclusive_price - $tax_amt;
		}
		elseif (!empty($tax_rate)) {
			if ($tax_rate >= 1) {
				user_error('Bad tax rate passed to Money::inclusive_to_exclusive');
				$tax_rate = $tax_rate / 100;
			}
			
			// tax exclusive price = inclusive price / (tax rate + 1)
			return $inclusive_price / ($tax_rate + 1);
		}
		
		return $inclusive_price;
	}
	
	/**
	 * Convert from a Tax Exclusive Price to a Tax Inclusive one
	 * @param float|int $exclusive_price
	 * @param float|int $tax_amt Tax dollar amount if known
	 * @param float|int|null $tax_rate Pass tax percentage if known. For 20% pass .2
	 * @return float Tax Inclusive Price
	 */
	public static function exclusive_to_inclusive(float|int $exclusive_price, float|int $tax_amt, float|int|null $tax_rate = null): float
	{
		if (!empty($tax_amt)) {
			return $exclusive_price + $tax_amt;
		}
		elseif (!empty($tax_rate)) {
			if ($tax_rate >= 1) {
				user_error('Bad tax rate passed to Money::exclusive_to_inclusive');
				$tax_rate = $tax_rate / 100;
			}
			
			// tax inclusive price = exclusive price * (tax rate + 1)
			return $exclusive_price * ($tax_rate + 1);
		}
		
		return $exclusive_price;
	}
	
	/**
	 * Retrieves the currency code for the provided ISO country code
	 * @param string $iso_code Accepts either 2 or 3 digit
	 * @return string
	 */
	/*
	static public function get_currency_from_country(string $iso_code): string
	{
		$_code = trim($iso_code, " \t\n\r\0\x0B");

		if(strlen($iso_code) == 2) {
			$iso_code = Address::get_country_code($iso_code, 3);
		}

		if(empty($_code) || strlen($iso_code) != 3) {
			return '';
		}

		return self::$country_currency_map[$iso_code] ?? '';
	}
	*/
	
	/**
	 * Retrieves the currency symbol for the currency
	 * @param string $iso_code The 3 letter iso code for the currency
	 * @return string
	 */
	public static function get_currency_symbol(string $iso_code): string
	{
		$_code = trim($iso_code, " \t\n\r\0\x0B");
		
		if (empty($_code) || strlen($_code) != 3) {
			return '';
		}
		
		return self::$currency_code_map[$_code] ?? '';
	}
	
	/** @var array<string, string> $currency_code_map Maps the ISO 3 code to the currency symbol*/
	public static array $currency_code_map = [
		'ALL' =>  'Lek',
		'AFN' =>  '؋',
		'ARS' =>  '$',
		'AWG' =>  'ƒ',
		'AUD' =>  '$',
		'AZN' =>  '₼',
		'BSD' =>  '$',
		'BBD' =>  '$',
		'BYN' =>  'Br',
		'BZD' =>  'BZ$',
		'BMD' =>  '$',
		'BOB' =>  '$b',
		'BAM' =>  'KM',
		'BWP' =>  'P',
		'BGN' =>  'лв',
		'BRL' =>  'R$',
		'BND' =>  '$',
		'KHR' =>  '៛',
		'CAD' =>  '$',
		'KYD' =>  '$',
		'CLP' =>  '$',
		'CNY' =>  '¥',
		'COP' =>  '$',
		'CRC' =>  '₡',
		'HRK' =>  'kn',
		'CUP' =>  '₱',
		'CZK' =>  'Kč',
		'DKK' =>  'kr',
		'DOP' =>  'RD$',
		'XCD' =>  '$',
		'EGP' =>  '£',
		'SVC' =>  '$',
		'EUR' =>  '€',
		'FKP' =>  '£',
		'FJD' =>  '$',
		'GHS' =>  '¢',
		'GIP' =>  '£',
		'GTQ' =>  'Q',
		'GGP' =>  '£',
		'GYD' =>  '$',
		'HNL' =>  'L',
		'HKD' =>  '$',
		'HUF' =>  'Ft',
		'ISK' =>  'kr',
		'INR' =>  '₹',
		'IDR' =>  'Rp',
		'IRR' =>  '﷼',
		'IMP' =>  '£',
		'ILS' =>  '₪',
		'JMD' =>  'J$',
		'JPY' =>  '¥',
		'JEP' =>  '£',
		'KZT' =>  'лв',
		'KPW' =>  '₩',
		'KRW' =>  '₩',
		'KGS' =>  'лв',
		'LAK' =>  '₭',
		'LBP' =>  '£',
		'LRD' =>  '$',
		'MKD' =>  'ден',
		'MYR' =>  'RM',
		'MUR' =>  '₨',
		'MXN' =>  '$',
		'MNT' =>  '₮',
		'MZN' =>  'MT',
		'NAD' =>  '$',
		'NPR' =>  '₨',
		'ANG' =>  'ƒ',
		'NZD' =>  '$',
		'NIO' =>  'C$',
		'NGN' =>  '₦',
		'NOK' =>  'kr',
		'OMR' =>  '﷼',
		'PKR' =>  '₨',
		'PAB' =>  'B/.',
		'PYG' =>  'Gs',
		'PEN' =>  'S/.',
		'PHP' =>  '₱',
		'PLN' =>  'zł',
		'QAR' =>  '﷼',
		'RON' =>  'lei',
		'RUB' =>  '₽',
		'SHP' =>  '£',
		'SAR' =>  '﷼',
		'RSD' =>  'Дин.',
		'SCR' =>  '₨',
		'SGD' =>  '$',
		'SBD' =>  '$',
		'SOS' =>  'S',
		'ZAR' =>  'R',
		'LKR' =>  '₨',
		'SEK' =>  'kr',
		'CHF' =>  'CHF',
		'SRD' =>  '$',
		'SYP' =>  '£',
		'TWD' =>  'NT$',
		'THB' =>  '฿',
		'TTD' =>  'TT$',
		'TRY' =>  '',
		'TVD' =>  '$',
		'UAH' =>  '₴',
		'GBP' =>  '£',
		'USD' =>  '$',
		'UYU' =>  '$U',
		'UZS' =>  'лв',
		'VEF' =>  'Bs',
		'VND' =>  '₫',
		'YER' =>  '﷼',
		'ZWD' =>  'Z$'
	];
	
	/**
	 * @var array<string, string> $country_currency_map Maps the ISO e country code to the currency code
	 */
	public static array $country_currency_map = [
		'AUS' => 'AUD',
		'AUT' => 'EUR',
		'BEL' => 'EUR',
		'BRA' => 'BRL',
		'CAN' => 'CAD',
		'CHN' => 'CNY',
		'CYP' => 'EUR',
		'DEU' => 'EUR',
		'ESP' => 'EUR',
		'EST' => 'EUR',
		'FIN' => 'EUR',
		'FRA' => 'EUR',
		'GBR' => 'GBP',
		'GRC' => 'EUR',
		'IND' => 'INR',
		'IRL' => 'EUR',
		'ITA' => 'EUR',
		'LTU' => 'EUR',
		'LUX' => 'EUR',
		'LVA' => 'EUR',
		'MEX' => 'MXN',
		'MLT' => 'EUR',
		'NLD' => 'EUR',
		'PRT' => 'EUR',
		'SVK' => 'EUR',
		'SVN' => 'EUR',
		'USA' => 'USD',
		'POL' => 'PLN',
	];
}
