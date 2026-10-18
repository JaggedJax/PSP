<?php

namespace PSP\Tests\Db;

use PHPUnit\Framework\TestCase;
use PSP\Db\DB;
use PSP\Db\DBTable;
use PSP\Tests\Fixtures\Widget;

use function Patchwork\redefine;

class DBTableEmptyListTest extends TestCase
{
	private static int $queries = 0;

	protected function tearDown(): void
	{
		\Patchwork\restoreAll();
		self::$queries = 0;
	}

	private function count_queries(): void
	{
		redefine(DB::class . '::select', function () {
			self::$queries++;
			return false;
		});
	}

	public function testEmptyListMatchesNothingWithoutQuerying(): void
	{
		$this->count_queries();

		$this->assertSame([], Widget::load_from(['widget_id' => []]));
		$this->assertNull(Widget::load_from(['widget_id' => []], limit: 1));
		$this->assertSame([], Widget::load_from(['widget_id' => []], load_rules: DBTable::LOAD_AS_ARRAY));
		$this->assertSame(0, self::$queries);
	}
}
