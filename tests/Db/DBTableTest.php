<?php

namespace PSP\Tests\Db;

use PHPUnit\Framework\TestCase;
use PSP\Tests\Fixtures\Widget;
use PSP\Tests\Fixtures\WidgetPart;

class DBTableTest extends TestCase
{
	/**
	 * Read the protected list of changed fields that save() builds its query from
	 * @param object $object DBTable object to inspect
	 * @return string[] Changed DB column names
	 */
	private function changed_fields(object $object): array
	{
		$property = new \ReflectionProperty($object, '_fields_changed');

		return array_keys($property->getValue($object));
	}

	public function testAssigningAValueMatchingThePropertyDefaultIsNotSaved(): void
	{
		$widget = new Widget();
		// "" is already the property default, so the set hook sees no change
		$widget->name = '';
		$widget->status = 'pending';

		$this->assertSame(['status'], $this->changed_fields($widget));
	}

	public function testMarkFieldChangedForcesAColumnIntoTheNextSave(): void
	{
		$widget = new Widget();
		$widget->name = 'Sprocket';
		$widget->mark_field_changed('status');

		$changed = $this->changed_fields($widget);

		sort($changed);
		$this->assertSame(['name', 'status'], $changed);
	}

	public function testMarkFieldChangedAcceptsMultipleColumns(): void
	{
		$widget = new Widget();
		$widget->mark_field_changed('status', 'type');

		$changed = $this->changed_fields($widget);

		sort($changed);
		$this->assertSame(['status', 'type'], $changed);
	}

	public function testMarkFieldChangedIgnoresAnythingThatIsNotADBColumn(): void
	{
		$widget = new Widget();
		$widget->mark_field_changed('_note', 'not_a_column');

		$this->assertSame([], $this->changed_fields($widget));
	}

	public function testGetQueryCacheKeyGeneratesExpectedKey(): void
	{
		$key = WidgetPart::get_query_cache_key(['widget_id' => 123]);
		$this->assertStringStartsWith('dbautocache_widget_parts_hash', $key);
	}

	public function testClearQueryCacheDeletesAllMaskPermutations(): void
	{
		$deleted_keys = [];
		\Patchwork\redefine(\PSP\Cache\MemCache::class . '::delete', function (string|array $name) use (&$deleted_keys) {
			$deleted_keys = is_array($name) ? $name : [$name];
			return true;
		});

		WidgetPart::clear_query_cache(['widget_id' => 456]);

		$this->assertNotEmpty($deleted_keys);
		// Check that the default load rule key is included
		$expected_default_key = WidgetPart::get_query_cache_key(['widget_id' => 456]);
		$this->assertContains($expected_default_key, $deleted_keys);

		\Patchwork\restoreAll();
	}

	public function testClearWhereCacheAliasesClearQueryCache(): void
	{
		$deleted_keys = [];
		\Patchwork\redefine(\PSP\Cache\MemCache::class . '::delete', function (string|array $name) use (&$deleted_keys) {
			$deleted_keys = is_array($name) ? $name : [$name];
			return true;
		});

		WidgetPart::clear_where_cache(['widget_id' => 789]);

		$this->assertNotEmpty($deleted_keys);
		$expected_default_key = WidgetPart::get_query_cache_key(['widget_id' => 789]);
		$this->assertContains($expected_default_key, $deleted_keys);

		\Patchwork\restoreAll();
	}
}
