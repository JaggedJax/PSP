<?php

namespace PSP\Tests\Files;

use PHPUnit\Framework\TestCase;
use PSP\Files\CSV;

class CSVTest extends TestCase
{
	public function test_is_csv_corrupted_finds_excel_numbers(): void
	{
		$handle = fopen('php://memory', 'r+');
		fwrite($handle, "sku,upc\nA1,1.23457E+11\n");
		rewind($handle);

		$result = CSV::is_csv_corrupted('', $handle);

		$this->assertTrue($result['file_checked']);
		$this->assertSame([['line' => 2, 'column' => 2]], $result['corrupted_segments']);
	}

	public function test_is_csv_corrupted_leaves_a_caller_handle_open(): void
	{
		$handle = fopen('php://memory', 'r+');
		fwrite($handle, "a,b\n");
		rewind($handle);

		CSV::is_csv_corrupted('', $handle);

		$this->assertTrue(is_resource($handle));
		fclose($handle);
	}
}
