<?php

namespace PSP\Tests\Log;

use PSP\Log\LogTrail;
use PHPUnit\Framework\TestCase;

class LogTrailTest extends TestCase
{
	protected function setUp(): void
	{
		LogTrail::reset();
	}

	public function test_add_without_an_open_trail_does_nothing(): void
	{
		$this->assertSame('id1', LogTrail::add('id1'));
		$this->assertSame([], LogTrail::ids());
	}

	public function test_trails_nest_and_end_returns_their_ids(): void
	{
		LogTrail::begin();
		LogTrail::add('outer');
		LogTrail::begin();
		LogTrail::add('inner');
		$this->assertSame(['inner'], LogTrail::ids());
		$this->assertSame(['inner'], LogTrail::end());
		$this->assertSame(['outer'], LogTrail::end());
		$this->assertSame([], LogTrail::end());
	}

	public function test_pack_removes_duplicates_and_keeps_the_newest_ids(): void
	{
		$this->assertNull(LogTrail::pack([]));
		$this->assertSame('a,b', LogTrail::pack(['a', 'b', 'a']));

		$ids = array_map(fn ($i) => "id$i", range(1, LogTrail::MAX_IDS + 5));
		$packed = explode(',', LogTrail::pack($ids));
		$this->assertCount(LogTrail::MAX_IDS, $packed);
		$this->assertSame('id' . (LogTrail::MAX_IDS + 5), end($packed));
	}

	public function test_begin_can_start_a_trail_with_shared_ids_without_changing_the_source(): void
	{
		LogTrail::begin();
		LogTrail::add('delivery');
		$shared = LogTrail::end();

		LogTrail::begin($shared);
		LogTrail::add('order-1');
		$this->assertSame(['delivery', 'order-1'], LogTrail::end());

		LogTrail::begin($shared);
		$this->assertSame(['delivery'], LogTrail::ids());
	}
}
