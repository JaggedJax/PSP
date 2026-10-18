<?php

namespace PSP\Tests\Fixtures;

use PSP\Db\DBTable;

/**
 * Test DB object for the widgets table
 *
 * @extends DBTable<Widget>
 */
class Widget extends DBTable
{
	/** @var array{name:string, pk_field:string, pk_type:string} */
	public static array $_table = [
		'name' => 'widgets',
		'pk_field' => 'widget_id',
		'pk_type' => 'int',
	];

	public ?int $widget_id = null;

	public string $name = '' {
		set(string $value) => $this->db_set_property_check('name', $value);
	}

	public string $status = '' {
		set(string $value) => $this->db_set_property_check('status', $value);
	}

	public string $type = '' {
		set(string $value) => $this->db_set_property_check('type', $value);
	}

	public string $_note = '';
}
