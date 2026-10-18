<?php

namespace PSP\Tests\Db;

use PHPUnit\Framework\TestCase;
use PSP\Db\DB;
use PSP\Db\DBTable;
use PSP\Tests\Fixtures\WidgetPart;

use function Patchwork\always;
use function Patchwork\redefine;

class DBTableIteratorTest extends TestCase
{
	/** @var array{where:mixed[], orderby:string, raw_where:array{sql:string, params:mixed[]}|null}[] Every DB::select() call made during the test, in order */
	private static array $calls = [];

	/** @var array[][] Queue of canned result pages, one array of rows per expected DB::select() call */
	private static array $pages = [];

	protected function setUp(): void
	{
		parent::setUp();

		self::$calls = [];
		self::$pages = [];

		// Never touch a real database from these tests
		$calls = &self::$calls;
		$pages = &self::$pages;
		redefine(DB::class . '::connect', always(null));
		// Parameter names must match DB::select()'s real signature: Patchwork preserves the original
		// declaration for argument binding, so callers using named args (eg. raw_where:) resolve by
		// position against these names, not against whatever this closure happens to call them.
		redefine(DB::class . '::select', function (
			$table_name,
			$arr_where_parameters = [],
			$arr_return_columns = '*',
			$arr_order_by_parameters = '',
			$limit = null,
			$return_result = true,
			$where_and = true,
			$group_by = [],
			$having = '',
			$raw_where = null
		) use (&$calls, &$pages) {
			$calls[] = [
				'where' => $arr_where_parameters,
				'orderby' => $arr_order_by_parameters,
				'raw_where' => $raw_where,
			];
			return array_shift($pages) ?? [];
		});
	}

	protected function tearDown(): void
	{
		\Patchwork\restoreAll();
		parent::tearDown();
	}

	/**
	 * Cursor pagination across two order-by fields (widget_id ASC, widget_part_pk DESC) must:<br>
	 * - move to the next value of the leading field once its ties are exhausted (page 1 -> 2)<br>
	 * - stay on the same leading-field value and keep advancing the second field otherwise (page 2 -> 3)
	 */
	public function testIteratesInOrderAcrossMultipleCursorFields(): void
	{
		self::$pages = [
			// Page 1: batch of 3, still full -> not the final page
			[
				['widget_part_pk' => 103, 'widget_id' => 1],
				['widget_part_pk' => 102, 'widget_id' => 1],
				['widget_part_pk' => 101, 'widget_id' => 1],
			],
			// Page 2: crosses from widget_id 1 -> 2 -> 3, still full
			[
				['widget_part_pk' => 205, 'widget_id' => 2],
				['widget_part_pk' => 204, 'widget_id' => 2],
				['widget_part_pk' => 301, 'widget_id' => 3],
			],
			// Page 3: remainder within widget_id 3, short page -> final
			[
				['widget_part_pk' => 300, 'widget_id' => 3],
			],
		];

		$iterator = WidgetPart::load_from_iterate([], 'widget_id ASC, widget_part_pk DESC', 3, DBTable::LOAD_AS_ARRAY);

		$seen = [];
		foreach ($iterator as $row) {
			$seen[] = $row['widget_part_pk'];
		}

		$this->assertSame([103, 102, 101, 205, 204, 301, 300], $seen);
		$this->assertCount(3, self::$calls);

		// First page has no cursor yet
		$this->assertNull(self::$calls[0]['raw_where']);

		// Second page seeks past (widget_id=1, widget_part_pk=101), crossing into widget_id 2/3
		$this->assertSame(
			'(widget_id > ?) OR (widget_id = ? AND widget_part_pk < ?)',
			self::$calls[1]['raw_where']['sql']
		);
		$this->assertSame([1, 1, 101], self::$calls[1]['raw_where']['params']);

		// Third page seeks past (widget_id=3, widget_part_pk=301), staying within widget_id 3
		$this->assertSame(
			'(widget_id > ?) OR (widget_id = ? AND widget_part_pk < ?)',
			self::$calls[2]['raw_where']['sql']
		);
		$this->assertSame([3, 3, 301], self::$calls[2]['raw_where']['params']);
	}

	public function testRejectsWhereClauseConflictingWithOrderbyDirection(): void
	{
		$this->expectException(\Exception::class);

		// widget_id ASC pagination requires any pre-filter on that column to use a '>' style op
		WidgetPart::load_from_iterate(['widget_id' => ['op' => '<', 'val' => 5]], 'widget_id ASC, widget_part_pk DESC');
	}
}
