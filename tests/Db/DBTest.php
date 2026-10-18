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

	/**
	 * @return array<string, array{string}>
	 */
	public static function write_queries(): array
	{
		return [
			'update' => ['UPDATE widgets SET name = ?'],
			'delete' => ['DELETE FROM widgets WHERE widget_id = ?'],
			'insert' => ["\n\t\tINSERT INTO widgets (name) VALUES (?)"],
			'with then delete' => ['WITH old AS (SELECT widget_id FROM widgets) DELETE FROM widgets WHERE widget_id IN (SELECT widget_id FROM old)'],
		];
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function read_queries(): array
	{
		return [
			'select' => ['SELECT 1'],
			'leading whitespace and parenthesis' => ["\n\t\t\t(SELECT name FROM widgets) UNION (SELECT name FROM widget_parts)"],
			'recursive cte' => ['WITH RECURSIVE ids AS (SELECT 1 AS id) SELECT id FROM ids'],
			'show' => ['SHOW TABLES'],
			'explain' => ['EXPLAIN SELECT 1'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('write_queries')]
	public function test_replica_refuses_a_write_before_it_runs(string $query): void
	{
		$this->expectException(\PDOException::class);

		// connect() is stubbed, so reaching prepare() would fail with an Error instead
		DB::instance(true)->run_unnamed_query($query, ['Sprocket']);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('write_queries')]
	public function test_replica_refuses_a_write_that_asks_for_rows(string $query): void
	{
		$this->expectException(\PDOException::class);

		DB::instance(true)->run_unnamed_query($query, ['Sprocket'], true);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('read_queries')]
	public function test_replica_lets_reads_through(string $query): void
	{
		$this->expectException(\Error::class);

		// The replica check lets the read through, so it reaches prepare() on the stubbed connection
		DB::instance(true)->run_unnamed_query($query, [], true);
	}
}
