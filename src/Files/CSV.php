<?php

namespace PSP\Files;

use PSP\Log\Log;

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
		
		if (empty($handle)) {
			if (empty($file)) {
				return $result;
			}
			$handle = fopen($file, 'r');
		}
		
		//detect excel corruption of data here ==> read files and do simple regex before uploading
		if ($handle !== false && is_resource($handle)) {
			$result['file_checked'] = true;
			$line = 1;
			while (($data = fgetcsv($handle, 0, ",")) !== false) {
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
			fclose($handle);
		}
		
		return $result;
	}
	
	/**
	 * Convert a column number to an excel style column name. 0 indexed
	 * @param int $num
	 * @return string Excel Column name
	 */
	/*
	public static function column_num_to_letter(int $num){
		// PhpSpreadsheet uses 1-index, so we add one from our 0 index
		$ch = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($num+1);

		return $ch;
	}
	*/
	
	/**
	 * Convert an Excel file to a CSV
	 * @param string $xls_file_path Full path to input Excel file
	 * @param string $csv_file_path Full path to output CSV file. Should not exist yet.
	 * @param boolean $delete_xlsx Delete Excel file after successful conversion?
	 * @return bool Was conversion a success
	 */
	/*
	public static function convert_xlsx_to_csv($xls_file_path, $csv_file_path, $delete_xlsx = false) {
		if (!file_exists($xls_file_path)) {
			Log::warning('Excel file not found: ' . $csv_file_path);
			return false;
		}

		$reader = new Xlsx();
		// Load Excel File
		@$spreadsheet = $reader->load($xls_file_path);

		if (!$spreadsheet->getSheetCount()) {
			Log::warning('Unable to parse Excel file for conversion: ' . $xls_file_path);
			return false;
		}

		// Get First Sheet only
		$sheetIndex = $spreadsheet->getFirstSheetIndex();

		// Start new CSV object
		$writer = new Csv($spreadsheet);
		// Set it to the first sheet of the Excel file
		try {
			$writer->setSheetIndex($sheetIndex);
			$writer->save($csv_file_path);
		} catch (\Exception $e) {
			// Unable to convert file. Probably not a valid Excel file
			Log::warning('Unable to write new CSV file for Excel conversion: ' . $csv_file_path);

			return false;
		}

		if (!file_exists($csv_file_path)) {
			Log::warning('Failed to write new CSV file for Excel conversion: ' . $csv_file_path);
			return false;
		}

		if ($delete_xlsx) {
			unlink($xls_file_path);
		}

		return true;
	}
	*/

}
