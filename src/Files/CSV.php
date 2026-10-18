<?php

namespace PSP\Files;

class CSV
{
	/**
	 * Check for corrupted elements in CSV file. Looks for:<br>
	 * - Corrupted numbers: 2.234E+64
	 * @param string $file
	 * @param resource|null $handle
	 * @return array{file_checked: bool, corrupted_segments: array{}|array{array{line: int, column: int}}}
	 */
	public static function is_csv_corrupted(string $file, $handle = null): array
	{
		$result = [
			'file_checked' => false,
			'corrupted_segments' => [],
		];
		
		$opened_here = empty($handle);
		if ($opened_here) {
			if (empty($file)) {
				return $result;
			}
			$handle = fopen($file, 'r');
		}
		
		//detect excel corruption of data here ==> read files and do simple regex before uploading
		if ($handle !== false && is_resource($handle)) {
			$result['file_checked'] = true;
			$line = 1;
			while (($data = fgetcsv($handle, 0, ",", '"', '')) !== false) {
				$column = 1;
				foreach ($data as $c) {
					//    /^\d\.\d{5}E\+\d{1,2}$/i  ==> start with one digit, then dot, then 1-5 digits, then E+, then 1 or 2 digit
					if (preg_match("/^\d\.\d+E\+\d{1,2}$/i", $c)) {
						$result['corrupted_segments'][] = ['line' => $line, 'column' => $column];
					}
					$column++;
				}
				$line++;
			}
			if ($opened_here) {
				fclose($handle);
			}
		}
		
		return $result;
	}
}
