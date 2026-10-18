<?php

namespace PSP\Util;

class Dates
{
	/**
	 * Get the US time zones a user can choose from
	 * @return array<string, string> ['America/Los_Angeles' => 'Pacific Time']
	 */
	public static function us_time_zone_list(): array
	{
		return [
			'America/New_York' => 'Eastern Time',
			'America/Chicago' => 'Central Time',
			'America/Denver' => 'Mountain Time',
			'America/Phoenix' => 'Mountain Time - Arizona (no daylight saving)',
			'America/Los_Angeles' => 'Pacific Time',
			'America/Anchorage' => 'Alaska Time',
			'Pacific/Honolulu' => 'Hawaii Time',
		];
	}

	/**
	 * Get list of time zones with Key as the PHP format and Value as the display format
	 * @return array<string, string> ['America/Los_Angeles' => 'US - Pacific Time', 'America/New_York' => 'US - Eastern Time']
	 * @throws \Exception
	 */
	public static function time_zone_list(): array
	{
		// Save this between function calls
		static $timezones = null;
		
		if ($timezones === null) {
			$timezones = [];
			$offsets = [];
			$now = new \DateTime('now', new \DateTimeZone('UTC'));
			
			foreach (\DateTimeZone::listIdentifiers() as $timezone) {
				$now->setTimezone(new \DateTimeZone($timezone));
				$offsets[] = $offset = $now->getOffset();
				$timezones[$timezone] = self::format_timezone_name($timezone) . ' (' . self::format_GMT_offset($offset) . ')';
			}
			
			array_multisort($offsets, $timezones);
		}
		
		return $timezones;
	}
	
	/**
	 * Convert from timezone offset in seconds, to a string format: GMT+01:00
	 * @param int $offset Timezone offset in Seconds
	 * @return string GMT+01:00
	 */
	public static function format_GMT_offset(int $offset): string
	{
		$hours = intval($offset / 3600);
		$minutes = abs(intval($offset % 3600 / 60));
		return 'GMT' . ($offset ? sprintf('%+03d:%02d', $hours, $minutes) : '');
	}
	
	/**
	 * Make a timezone name string more human friendly
	 * @param string|string[] $name
	 * @return string|string[]
	 */
	public static function format_timezone_name(array|string $name): array|string
	{
		return str_replace(['/', '_', 'St '], [', ', ' ', 'St. '], $name);
	}
	
	/**
	 * Return ordinal suffix for an integer
	 * @param int $num
	 * @return string Suffix. Examples: st, nd, rd
	 */
	public static function ordinal_suffix(int $num): string
	{
		if ($num < 11 || $num > 13) {
			switch ($num % 10) {
				case 1:
					return 'st';
				case 2:
					return 'nd';
				case 3:
					return 'rd';
			}
		}
		return 'th';
	}
	
	/**
	 * Convert cron time value to human friendly text
	 * @param string $time Example: *\/15
	 * @return string
	 */
	public static function cron_time_to_text(string $time): string
	{
		// Every
		if ($time == '*') {
			return 'every';
		}
		// Every X
		elseif (str_contains($time, '*/')) {
			$num = substr($time, 2);
			return "every $num" . self::ordinal_suffix((int) $num);
		}
		// Range
		elseif (($dash_pos = strpos($time, '-'))) {
			return substr($time, 0, $dash_pos) . self::ordinal_suffix((int) substr($time, 0, $dash_pos))
				. ' through '
				. substr($time, $dash_pos + 1) . self::ordinal_suffix((int) substr($time, $dash_pos + 1));
		}
		// Specific values
		else {
			$times = explode(',', $time);
			$result = '';
			$num_times = count($times);
			for ($i = 0; $i < $num_times; $i++) {
				if ($i + 1 == $num_times) {
					$and = ($num_times > 1) ? 'and ' : '';
					$result .= $and . $times[$i] . self::ordinal_suffix((int) $times[$i]);
				}
				else {
					$result .= $times[$i] . self::ordinal_suffix((int) $times[$i]) . ', ';
				}
			}
			return $result;
		}
	}
	
	/**
	 * Convert the five cron fields to a short human friendly name. Example: "Every 15 minutes", "Every day at 2:30 AM"
	 * Returns the raw expression when the pattern is not a common one.
	 * @param string $minute
	 * @param string $hour
	 * @param string $day_of_month
	 * @param string $month
	 * @param string $day_of_week
	 * @return string
	 */
	public static function cron_to_friendly_name(string $minute, string $hour, string $day_of_month, string $month, string $day_of_week): string
	{
		$raw = "$minute $hour $day_of_month $month $day_of_week";
		$step = static fn (string $field): ?int => preg_match('#^\*/(\d+)$#', $field, $m) && (int) $m[1] > 0 ? (int) $m[1] : null;
		$plural = static fn (int $n, string $unit): string => $n === 1 ? "Every $unit" : "Every $n {$unit}s";
		$is_num = static fn (string $field): bool => ctype_digit($field);

		if ($month !== '*') {
			return $raw;
		}

		// Sub-daily: every minute, every N minutes, every N hours
		if ($day_of_month === '*' && $day_of_week === '*') {
			if ($hour === '*') {
				if ($minute === '*') {
					return 'Every minute';
				}
				if (($n = $step($minute)) !== null) {
					return $plural($n, 'minute');
				}
				if ($is_num($minute)) {
					return $minute === '0' ? 'Every hour' : "Every hour at :" . str_pad($minute, 2, '0', STR_PAD_LEFT);
				}
			}
			elseif (($n = $step($hour)) !== null && $is_num($minute)) {
				return $plural($n, 'hour') . ($minute === '0' ? '' : " at :" . str_pad($minute, 2, '0', STR_PAD_LEFT));
			}
		}

		if (!$is_num($minute) || !$is_num($hour) || (int) $minute > 59 || (int) $hour > 23) {
			return $raw;
		}
		$time = date('g:i A', mktime((int) $hour, (int) $minute));

		if ($day_of_month === '*' && $day_of_week === '*') {
			return "Every day at $time";
		}
		if ($day_of_month === '*') {
			$days = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
			if ($day_of_week === '1-5') {
				return "Every weekday at $time";
			}
			if (preg_match('#^[0-7](,[0-7])*$#', $day_of_week)) {
				$names = array_map(static fn ($d) => $days[(int) $d], explode(',', $day_of_week));
				$last = array_pop($names);
				return 'Every ' . ($names ? implode(', ', $names) . ' and ' : '') . "$last at $time";
			}
		}
		elseif ($day_of_week === '*' && $is_num($day_of_month)) {
			return "Monthly on the {$day_of_month}" . self::ordinal_suffix((int) $day_of_month) . " at $time";
		}

		return $raw;
	}

	/**
	 * Given a DB cron value, return the values needed for dropdown edit boxes
	 * @param string $value DB cron value
	 * @return array{string, string[]}
	 */
	public static function cron_type_and_values($value)
	{
		$type = '';
		$values = [];
		// Every
		if ($value == '*') {
			$type = '*';
		}
		// Every Xth
		elseif (strpos($value, '*/') !== false) {
			$type = '*/';
			$values[0] = substr($value, 2);
		}
		// Range
		elseif ($dash_pos = strpos($value, '-')) {
			$type = '-';
			$values[0] = substr($value, 0, $dash_pos);
			$values[1] = substr($value, $dash_pos + 1);
		}
		// Specific Values
		else {
			$type = ',';
			$values = explode(',', $value);
		}
		
		return [$type, $values];
	}

	/**
	 * Short text for the time since a date, for example "45m", "5h 10m" or "3d 4h"
	 * @param string|null $date Any date string that DateTimeImmutable accepts
	 * @param \DateTimeImmutable|null $now Reference time. Defaults to now
	 * @return string Empty string for an empty or invalid date
	 */
	public static function age(?string $date, ?\DateTimeImmutable $now = null): string
	{
		if (empty($date)) {
			return '';
		}
		try {
			$from = new \DateTimeImmutable($date);
		}
		catch (\Exception) {
			return '';
		}

		$seconds = max(0, ($now ?? new \DateTimeImmutable('now'))->getTimestamp() - $from->getTimestamp());
		$days = intdiv($seconds, 86400);
		$hours = intdiv($seconds % 86400, 3600);
		$minutes = intdiv($seconds % 3600, 60);

		if ($days > 0) {
			return $days . 'd' . ($hours > 0 ? " {$hours}h" : '');
		}
		if ($hours > 0) {
			return $hours . 'h' . ($minutes > 0 ? " {$minutes}m" : '');
		}
		return $minutes . 'm';
	}
}
