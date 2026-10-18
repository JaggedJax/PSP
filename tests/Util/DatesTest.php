<?php

namespace PSP\Tests\Util;

use PSP\Util\Dates;
use PHPUnit\Framework\TestCase;

class DatesTest extends TestCase
{
	private string $original_timezone;

	protected function setUp(): void
	{
		parent::setUp();
		$this->original_timezone = date_default_timezone_get();
	}

	protected function tearDown(): void
	{
		date_default_timezone_set($this->original_timezone);
		parent::tearDown();
	}

	public function test_age_formats_minutes_hours_and_days(): void
	{
		$now = new \DateTimeImmutable('2026-09-30 12:00:00');

		$this->assertSame('0m', Dates::age('2026-09-30 12:00:00', $now));
		$this->assertSame('45m', Dates::age('2026-09-30 11:15:00', $now));
		$this->assertSame('5h 10m', Dates::age('2026-09-30 06:50:00', $now));
		$this->assertSame('2h', Dates::age('2026-09-30 10:00:00', $now));
		$this->assertSame('3d 4h', Dates::age('2026-09-27 08:00:00', $now));
		$this->assertSame('1d', Dates::age('2026-09-29 12:00:00', $now));
	}

	public function test_age_is_empty_for_missing_or_invalid_dates(): void
	{
		$this->assertSame('', Dates::age(null));
		$this->assertSame('', Dates::age(''));
		$this->assertSame('', Dates::age('not a date'));
	}

	public function test_cron_to_friendly_name(): void
	{
		$this->assertSame('Every minute', Dates::cron_to_friendly_name('*', '*', '*', '*', '*'));
		$this->assertSame('Every 15 minutes', Dates::cron_to_friendly_name('*/15', '*', '*', '*', '*'));
		$this->assertSame('Every hour', Dates::cron_to_friendly_name('0', '*', '*', '*', '*'));
		$this->assertSame('Every hour at :30', Dates::cron_to_friendly_name('30', '*', '*', '*', '*'));
		$this->assertSame('Every 6 hours', Dates::cron_to_friendly_name('0', '*/6', '*', '*', '*'));
		$this->assertSame('Every day at 2:30 AM', Dates::cron_to_friendly_name('30', '2', '*', '*', '*'));
		$this->assertSame('Every day at 12:00 AM', Dates::cron_to_friendly_name('0', '0', '*', '*', '*'));
		$this->assertSame('Every weekday at 9:00 AM', Dates::cron_to_friendly_name('0', '9', '*', '*', '1-5'));
		$this->assertSame('Every Monday and Friday at 6:00 PM', Dates::cron_to_friendly_name('0', '18', '*', '*', '1,5'));
		$this->assertSame('Monthly on the 1st at 3:00 AM', Dates::cron_to_friendly_name('0', '3', '1', '*', '*'));
		$this->assertSame('0 3 1 6 *', Dates::cron_to_friendly_name('0', '3', '1', '6', '*'));
	}

	public function test_age_never_goes_negative(): void
	{
		$now = new \DateTimeImmutable('2026-09-30 12:00:00');
		$this->assertSame('0m', Dates::age('2026-09-30 13:00:00', $now));
	}

	public function test_us_time_zone_list_has_only_valid_us_zones(): void
	{
		$zones = Dates::us_time_zone_list();
		$this->assertArrayHasKey('America/Los_Angeles', $zones);
		foreach (array_keys($zones) as $zone) {
			$this->assertContains($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::AMERICA | \DateTimeZone::PACIFIC));
		}
	}
}
