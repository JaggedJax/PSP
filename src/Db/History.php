<?php

namespace PSP\Db;

use PSP\Log\LogTrail;

class History
{
	/** @var (callable(): (string|int|null))|null Returns who made the change. EG: user_id:123 */
	private static $changed_by_resolver = null;

	/**
	 * Set the function that returns who made a change. Call once from the app bootstrap
	 * @param callable(): (string|int|null) $resolver
	 */
	public static function set_changed_by_resolver(callable $resolver): void
	{
		self::$changed_by_resolver = $resolver;
	}

	/**
	 * Create new history entry
	 * @param string $table EG: users
	 * @param string $field_changed EG: email or settings.notify. Use ADDED/REMOVED for new/deleted records
	 * @param int $record_pk PK value for the db_table being updated. EG. a user_id
	 * @param null|string|int|float|array $old_val Array when record deleted. Will be JSON encoded
	 * @param null|string|int|float|array $new_val Array when record created. Will be JSON encoded
	 * @param list<string>|null $log_ids Log IDs that explain the change. Null uses the open LogTrail
	 * @return bool Success
	 */
	public static function new_entry(string $table, string $field_changed, int $record_pk, mixed $old_val, mixed $new_val, ?array $log_ids = null): bool
	{
		$row = self::build_row($table, $field_changed, $record_pk, $old_val, $new_val, $log_ids);
		if ($row === null) {
			// Nothing to log if no change was made
			return true;
		}

		return DB::instance()->insert('history', $row);
	}

	/**
	 * Create several history entries in a single DB round-trip. Use this instead of calling
	 * new_entry() in a loop when logging many entries at once (eg. one per line while routing
	 * an order), so a batch job doesn't do one INSERT per row
	 * @param array<int,array{table:string, field:string, record:int, old_val:mixed, new_val:mixed, log_ids?:list<string>}> $entries
	 * @return bool Success
	 */
	public static function new_entries(array $entries): bool
	{
		$rows = [];
		foreach ($entries as $entry) {
			$row = self::build_row($entry['table'], $entry['field'], $entry['record'], $entry['old_val'], $entry['new_val'], $entry['log_ids'] ?? null);
			if ($row !== null) {
				$rows[] = $row;
			}
		}

		// Nothing to log if no entry made an actual change
		if (empty($rows)) {
			return true;
		}

		return DB::instance()->insert('history', $rows);
	}

	/**
	 * Build one history table row, redacting passwords and JSON-encoding array values
	 * @param string $table EG: users
	 * @param string $field_changed EG: email or settings.notify. Use ADDED/REMOVED for new/deleted records
	 * @param int $record_pk PK value for the db_table being updated. EG. a user_id
	 * @param null|string|int|float|array $old_val Array when record deleted. Will be JSON encoded
	 * @param null|string|int|float|array $new_val Array when record created. Will be JSON encoded
	 * @param list<string>|null $log_ids Log IDs that explain the change. Null uses the open LogTrail
	 * @return array{table:string, field:string, record:int, old_value:mixed, new_value:mixed, changed_by:string|int, log_ids:?string}|null Null if old_val and new_val are the same, ie. no change to log
	 */
	private static function build_row(string $table, string $field_changed, int $record_pk, mixed $old_val, mixed $new_val, ?array $log_ids = null): ?array
	{
		// Nothing to log if no change was made
		if ($old_val === $new_val) {
			return null;
		}

		if (is_array($old_val)) {
			if (isset($old_val['password'])) {
				$old_val['password'] = '*REDACTED*';
			}
			$old_val = json_encode($old_val);
		}
		if (is_array($new_val)) {
			if (isset($new_val['password'])) {
				$new_val['password'] = '*REDACTED*';
			}
			$new_val = json_encode($new_val);
		}

		return [
			'table' => $table,
			'field' => $field_changed,
			'record' => $record_pk,
			'old_value' => $old_val,
			'new_value' => $new_val,
			//TODO: Log if this came from an API all using $_SERVER['PHP_AUTH_USER']
			'changed_by' => (self::$changed_by_resolver !== null ? (self::$changed_by_resolver)() : null) ?? 0,
			'log_ids' => LogTrail::pack($log_ids ?? LogTrail::ids()),
		];
	}

	/**
	 * Get history entries recorded for a record, newest first
	 * @param string $table EG: users
	 * @param int $record_pk PK value for the db_table the entries were recorded against
	 * @return array<int,array{history_pk:int,table:string,field:string,record:int,changed_date:string,old_value:?string,new_value:?string,changed_by:?string,log_ids:?string}>
	 */
	public static function get_for_record(string $table, int $record_pk): array
	{
		return DB::instance()->select(
			'history',
			['`table`' => $table, 'record' => $record_pk],
			'*',
			'changed_date DESC'
		) ?: [];
	}

}
