<?php

namespace PSP\Tests\Db;

use PHPUnit\Framework\TestCase;
use PSP\Db\DB;

use function Patchwork\redefine;

class DBTest extends TestCase
{
	protected function setUp(): void
	{
		// Patchwork binds the closure to the DB instance
		redefine(DB::class . '::connect', function () {
			$this->connected = true; // @phpstan-ignore property.notFound
		});
	}

	protected function tearDown(): void
	{
		\Patchwork\restoreAll();
	}

	public function test_update_without_where_parameters_does_not_run(): void
	{
		redefine(DB::class . '::run_unnamed_query', function () {
			throw new \RuntimeException('No query should run');
		});

		$this->assertFalse(DB::instance()->update('widgets', [], ['name' => 'Sprocket']));
		$this->assertFalse(DB::instance()->update('widgets', ['widget_id' => 1], []));
	}

	public function test_replica_refuses_a_write_before_it_runs(): void
	{
		$this->expectException(\PDOException::class);

		// connect() is stubbed, so reaching prepare() would fail with an Error instead
		DB::instance(true)->run_unnamed_query('UPDATE widgets SET name = ?', ['Sprocket']);
	}

	public function test_replica_still_returns_rows_for_a_select(): void
	{
		$this->expectException(\Error::class);

		// The replica check lets SELECT through, so it reaches prepare() on the stubbed connection
		DB::instance(true)->run_unnamed_query('SELECT 1', [], true);
	}
}
