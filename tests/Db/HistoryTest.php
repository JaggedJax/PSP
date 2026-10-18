<?php

namespace PSP\Tests\Db;

use PSP\Db\DB;
use PSP\Log\LogTrail;
use PSP\Db\History;
use PHPUnit\Framework\TestCase;

use function Patchwork\always;
use function Patchwork\redefine;

/**
 * Covers History::new_entry()/new_entries()/get_for_record(). DB::connect()/insert()/select() are
 * mocked so these run against in-memory data only, no DB required.
 */
class HistoryTest extends TestCase
{
	protected function setUp(): void
	{
		redefine(DB::class . '::connect', always(null));
	}

	protected function tearDown(): void
	{
		\Patchwork\restoreAll();
		parent::tearDown();
	}

	public function test_new_entry_skips_insert_when_old_and_new_values_are_identical(): void
	{
		redefine(DB::class . '::insert', function () {
			throw new \RuntimeException('DB::insert should not be called when nothing changed');
		});

		$this->assertTrue(History::new_entry('customer_orders', 'order_status', 1, 'pending', 'pending'));
	}

	public function test_new_entry_inserts_a_row_with_the_expected_columns(): void
	{
		$inserted = null;
		redefine(DB::class . '::insert', function ($table, $fields) use (&$inserted) {
			$inserted = ['table' => $table, 'fields' => $fields];
			return true;
		});

		$result = History::new_entry('customer_orders', 'order_status', 42, 'pending', 'ready');

		$this->assertTrue($result);
		$this->assertSame('history', $inserted['table']);
		$this->assertSame('customer_orders', $inserted['fields']['table']);
		$this->assertSame('order_status', $inserted['fields']['field']);
		$this->assertSame(42, $inserted['fields']['record']);
		$this->assertSame('pending', $inserted['fields']['old_value']);
		$this->assertSame('ready', $inserted['fields']['new_value']);
		$this->assertArrayHasKey('changed_by', $inserted['fields']);
	}

	public function test_new_entry_saves_changed_by_from_the_resolver(): void
	{
		$inserted = null;
		redefine(DB::class . '::insert', function ($table, $fields) use (&$inserted) {
			$inserted = $fields;
			return true;
		});

		History::set_changed_by_resolver(fn () => 'user_id:7');
		History::new_entry('users', 'name', 7, 'Ann', 'Anne');
		$this->assertSame('user_id:7', $inserted['changed_by']);

		History::set_changed_by_resolver(fn () => null);
		History::new_entry('users', 'name', 7, 'Anne', 'Ann');
		$this->assertSame(0, $inserted['changed_by']);
	}

	public function test_new_entry_saves_explicit_log_ids(): void
	{
		$inserted = null;
		redefine(DB::class . '::insert', function ($table, $fields) use (&$inserted) {
			$inserted = $fields;
			return true;
		});

		History::new_entry('warehouse_order', 'order_status', 5, 'pending', 'at_wms', ['261012120000-aaaaaa', '261012120001-bbbbbb']);

		$this->assertSame('261012120000-aaaaaa,261012120001-bbbbbb', $inserted['log_ids']);
	}

	public function test_new_entry_uses_the_open_log_trail_when_no_log_ids_are_given(): void
	{
		$inserted = null;
		redefine(DB::class . '::insert', function ($table, $fields) use (&$inserted) {
			$inserted = $fields;
			return true;
		});

		LogTrail::reset();
		LogTrail::begin();
		LogTrail::add('261012120000-aaaaaa');
		History::new_entry('warehouse_order', 'order_status', 5, 'pending', 'at_wms');
		LogTrail::end();
		History::new_entry('warehouse_order', 'order_status', 5, 'at_wms', 'shipped');

		$this->assertNull($inserted['log_ids']);
	}

	public function test_new_entries_save_log_ids_for_each_entry(): void
	{
		$inserted = null;
		redefine(DB::class . '::insert', function ($table, $fields) use (&$inserted) {
			$inserted = $fields;
			return true;
		});

		History::new_entries([
			['table' => 'warehouse_order', 'field' => 'order_status', 'record' => 1, 'old_val' => 'a', 'new_val' => 'b', 'log_ids' => ['x1']],
			['table' => 'warehouse_order', 'field' => 'order_status', 'record' => 2, 'old_val' => 'a', 'new_val' => 'b'],
		]);

		$this->assertSame('x1', $inserted[0]['log_ids']);
		$this->assertNull($inserted[1]['log_ids']);
	}

	public function test_new_entry_json_encodes_array_values_and_redacts_password(): void
	{
		$inserted = null;
		redefine(DB::class . '::insert', function ($table, $fields) use (&$inserted) {
			$inserted = $fields;
			return true;
		});

		History::new_entry(
			'users',
			'ADDED',
			7,
			null,
			['user_id' => 7, 'email' => 'a@example.com', 'password' => 'super-secret']
		);

		$this->assertNull($inserted['old_value']);
		$decoded = json_decode($inserted['new_value'], true);
		$this->assertSame(7, $decoded['user_id']);
		$this->assertSame('a@example.com', $decoded['email']);
		$this->assertSame('*REDACTED*', $decoded['password'], 'Password should be redacted before being logged');
	}

	public function test_new_entries_writes_all_rows_in_a_single_insert_call(): void
	{
		$insert_calls = [];
		redefine(DB::class . '::insert', function ($table, $fields) use (&$insert_calls) {
			$insert_calls[] = ['table' => $table, 'fields' => $fields];
			return true;
		});

		$result = History::new_entries([
			['table' => 'customer_orders', 'field' => 'order_status', 'record' => 1, 'old_val' => 'pending', 'new_val' => 'ready'],
			['table' => 'customer_orders', 'field' => 'order_status', 'record' => 2, 'old_val' => 'pending', 'new_val' => 'ready'],
			['table' => 'warehouse_order', 'field' => 'ADDED', 'record' => 100, 'old_val' => null, 'new_val' => ['warehouse_order_pk' => 100]],
		]);

		$this->assertTrue($result);
		$this->assertCount(1, $insert_calls, 'Expected exactly one DB round-trip for the whole batch');
		$this->assertCount(3, $insert_calls[0]['fields'], 'Expected all 3 entries to be present as separate rows');
		$this->assertSame(1, $insert_calls[0]['fields'][0]['record']);
		$this->assertSame(2, $insert_calls[0]['fields'][1]['record']);
		$this->assertSame(100, $insert_calls[0]['fields'][2]['record']);
	}

	public function test_new_entries_drops_noop_entries_but_keeps_real_changes(): void
	{
		$insert_calls = [];
		redefine(DB::class . '::insert', function ($table, $fields) use (&$insert_calls) {
			$insert_calls[] = $fields;
			return true;
		});

		History::new_entries([
			['table' => 'customer_orders', 'field' => 'order_status', 'record' => 1, 'old_val' => 'pending', 'new_val' => 'pending'],
			['table' => 'customer_orders', 'field' => 'order_status', 'record' => 2, 'old_val' => 'pending', 'new_val' => 'ready'],
		]);

		$this->assertCount(1, $insert_calls);
		$this->assertCount(1, $insert_calls[0], 'The no-op entry (old_val === new_val) should have been dropped');
		$this->assertSame(2, $insert_calls[0][0]['record']);
	}

	public function test_new_entries_does_not_touch_the_db_when_every_entry_is_a_noop(): void
	{
		redefine(DB::class . '::insert', function () {
			throw new \RuntimeException('DB::insert should not be called when every entry is a no-op');
		});

		$result = History::new_entries([
			['table' => 'customer_orders', 'field' => 'order_status', 'record' => 1, 'old_val' => 'pending', 'new_val' => 'pending'],
		]);

		$this->assertTrue($result);
	}

	public function test_new_entries_with_an_empty_array_does_not_touch_the_db(): void
	{
		redefine(DB::class . '::insert', function () {
			throw new \RuntimeException('DB::insert should not be called for an empty batch');
		});

		$this->assertTrue(History::new_entries([]));
	}

	public function test_get_for_record_queries_by_table_and_record_ordered_newest_first(): void
	{
		$select_args = null;
		$rows = [
			['history_pk' => 2, 'table' => 'warehouse_order', 'field' => 'order_status', 'record' => 100, 'changed_date' => '2026-01-02 00:00:00', 'old_value' => 'pending', 'new_value' => 'ready', 'changed_by' => '0'],
		];
		redefine(DB::class . '::select', function (...$args) use (&$select_args, $rows) {
			$select_args = $args;
			return $rows;
		});

		$result = History::get_for_record('warehouse_order', 100);

		$this->assertSame($rows, $result);
		$this->assertSame('history', $select_args[0]);
		$this->assertSame(['`table`' => 'warehouse_order', 'record' => 100], $select_args[1]);
		$this->assertSame('*', $select_args[2]);
		$this->assertSame('changed_date DESC', $select_args[3]);
	}

	public function test_get_for_record_returns_empty_array_when_nothing_found(): void
	{
		redefine(DB::class . '::select', always(null));

		$this->assertSame([], History::get_for_record('customer_orders', 999));
	}
}
