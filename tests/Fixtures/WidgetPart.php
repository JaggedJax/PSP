<?php

namespace PSP\Tests\Fixtures;

use PSP\Db\DBTable;

/**
 * Test DB object for the widget_parts table
 *
 * @extends DBTable<WidgetPart>
 */
class WidgetPart extends DBTable
{
	/** @var array{name:string, pk_field:string, pk_type:string} */
	public static array $_table = [
		'name' => 'widget_parts',
		'pk_field' => 'widget_part_pk',
		'pk_type' => 'int',
	];
	protected static int $_auto_cache = 3600;

	public int $widget_part_pk = 0 {
		set(?int $value) => $this->db_set_property_check('widget_part_pk', $value);
	}

	public int $widget_id = 0 {
		set(int $value) => $this->db_set_property_check('widget_id', $value);
	}
}
