<?php

namespace PSP\Tests\Db;

use PHPUnit\Framework\TestCase;
use PSP\Db\Dictionary;

class DictionaryTest extends TestCase
{
	public function test_get_one_picks_from_an_associative_list(): void
	{
		$dictionary = new class () extends Dictionary {
			public static function get_values(mixed $selector = null): mixed
			{
				return ['a' => 'Apple', 'b' => 'Banana'];
			}
		};

		$this->assertContains($dictionary::get_one(), ['Apple', 'Banana']);
	}

	public function test_get_one_is_null_for_an_empty_list(): void
	{
		$dictionary = new class () extends Dictionary {
			public static function get_values(mixed $selector = null): mixed
			{
				return [];
			}
		};

		$this->assertNull($dictionary::get_one());
	}
}
