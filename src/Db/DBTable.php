<?php

namespace PSP\Db;

use PSP\Cache\MemCache;
use PSP\Log\Log;
use PSP\Util\Crypt;
use PSP\Util\Html;
use PSP\Util\Money;

/**
 * Extend this class when creating an object based on a DB table.<br>
 * REQUIREMENTS:<br>
 * Create a property matching each DB field name<br>
 * Non DB field properties must begin with an underscore: _<br>
 * Properties linking tables must begin with a double underscore: __
 * @template D of DBTable
 */
abstract class DBTable
{
	public const int LOAD_DEFAULT = 0;
	/** @var int Load fields marked as lazy loaded */
	public const int LOAD_LAZY_FIELDS = 1;
	/** @var int use OR clause between where statements */
	public const int WHERE_OR = 2;
	/** @var int Ignore any cache when loading the object data. Does not cascade to linked tables that may be loaded as well */
	public const int DISALLOW_CACHE = 4;
	/** @var string Namespace incoming() adds to class names passed without one. EG: App\Objects\ */
	public static string $_object_namespace = '';
	/** @var int Return DB record as an array instead of a DBTable object. Outgoing references WILL NOT be auto-loaded */
	public const int LOAD_AS_ARRAY = 8;
	/** @var int Log Query this */
	public const int LOG_QUERY = 16;
	
	/**
	 * @var int List of load rules that affect caching
	 */
	private static int $_cache_mask = self::LOAD_DEFAULT | self::LOAD_LAZY_FIELDS | self::WHERE_OR;
	
	/**
	 * @var array{name:string, pk_field:string, pk_type:string, incoming?:array<class-string<D>, string>, lazy?:string[]}
	 */
	public static array $_table = [
		'name' => '',
		'pk_field' => '',
		'pk_type' => '',
		'incoming' => [], // class-string<static> => linked_pk_col_on_remote_table
		'lazy' => [], // Must implement $this->load_lazy() in property getter
	];
	
	/**
	 * @var int Can override on DB object. Set # seconds to cache objects for. Use for simple objects that will be re-used a lot
	 */
	protected static int $_auto_cache = 0;

	/** @var array<string, true> List of DB fields that have been changed and need saving. Key is field name, value is true/unset */
	protected array $_fields_changed = [];
	
	/** @var array<string, true> List of DB fields that have been loaded from DB. Key is field name, value is true/unset */
	protected array $_loaded_fields = [];
	
	/** @var bool Flag to skip change detection during loading */
	protected bool $_loading_data = false;
	
	/** @var int Load rules used when loading this object. Will cascade to objects called from here */
	protected int $_load_rules = self::LOAD_DEFAULT;

	/**
	 * Recommended you use incoming() to get this iterator instead
	 * @var array<class-string<D>, DBTableIterator<D>>|null $_incoming_refs
	 */
	public ?array $_incoming_refs = null;
	
	/**
	 * @var float Reserved for the results of Full Text searches. Between 0 and infinity. Usually 1 or above is a good match
	 */
	public float $_fts_match = 0;

	final public function __construct()
	{
	} // Disallow overriding

	/**
	 * Get incoming reference iterator for a specific class.
	 * This just calls $this->_incoming_refs[] but ensures proper docblocks
	 * @template T of DBTable
	 * @param class-string<T> $class_name Either Full namespace or if just object name, DBTable::$_object_namespace will be assumed
	 * @return DBTableIterator<T>|array
	 */
	public function incoming(string $class_name): DBTableIterator|array
	{
		// Strip leading backslash if it's there
		$class_name = ltrim($class_name, '\\');
		// If object name was passed w/o full namespace, assume it's in DBTable::$_object_namespace
		// This is convenient for twig access
		if (self::$_object_namespace !== '' && !str_starts_with($class_name, self::$_object_namespace)) {
			$class_name = self::$_object_namespace . $class_name;
		}
		if (!isset($this->_incoming_refs[$class_name])) {
			Log::error("FIXME: Called ".static::$_table['name']."->incoming() with unsupported class: $class_name");
		}
		return $this->_incoming_refs[$class_name] ?? [];
	}

	/**
	 * Returns a list of properties that DO NOT start with underscore: _<br>
	 * These represent table columns
	 * @return string[]
	 */
	public static function list_db_columns()
	{
		// Get property names as array values
		$properties = array_keys(get_class_vars(static::class));

		// Filter out properties starting with an _
		return array_filter($properties, function ($value) {
			return $value[0] !== '_';
		});

	}

	/**
	 * Returns a list of properties that start with a double underscore: __<br>
	 * These represent linked tables
	 * @return string[]
	 */
	protected static function list_linked_tables()
	{
		// Get property names as array values
		$properties = array_keys(get_class_vars(static::class));

		// Only load properties starting with: __
		return array_filter($properties, function ($value) {
			return str_starts_with($value, '__');
		});

	}

	/**
	 * Force one or more DB columns into the next save().
	 * A set hook only records a field as changed when the new value differs from the one already
	 * on the object, so assigning a value that matches a property default is never saved. New
	 * records need this for columns that have no DB default value, otherwise the column is left
	 * out of the INSERT entirely.
	 * @param string ...$fields DB column names. Anything that isn't a column is ignored
	 * @return void
	 */
	public function mark_field_changed(string ...$fields): void
	{
		$db_columns = static::list_db_columns();
		foreach ($fields as $field) {
			if (in_array($field, $db_columns, true)) {
				$this->_fields_changed[$field] = true;
			}
		}
	}

	/**
	 * Checks if property was changed during set Property Hook. Optionally Validates data format for DB
	 * @param string $field
	 * @param mixed $value
	 * @param array{string?:array{length?: int, trim?:bool, html?:bool, empty_to_null?:bool}, int?:array{min?: float, max?: float}, float?:array{min?: float, max?: float, precision?: int}, money?:array{min?: float, max?: float, precision?: int}, bool?:array{true: string}, timestamp?:true, enum?:class-string<\BackedEnum>, json?:array{remove_empty:bool}} $validation
	 * @return mixed Input value after any validations. NULL if value cannot be used at all.
	 */
	protected function db_set_property_check($field, $value, $validation = [])
	{
		try {
			$value = $this->db_field_validation($value, $validation);
		}
		catch (\Exception $e) {
			trigger_error(static::class."->{$field} could not validate value for DB insert: ".$e->getMessage(), E_USER_WARNING);
			return null;
		}

		if (!$this->_loading_data && (!isset($this->{$field}) || $value !== $this->{$field})) {
			$this->_fields_changed[$field] = true;
		}

		return $value;
	}

	/**
	 * Validate a foreign-key ID column from its set hook, clearing the linked object
	 * property when the ID changes.
	 * NOTE: Reading the linked property from here would trigger its lazy-load get hook
	 * and query the DB, so it is only ever written to.
	 * @param string $field FK column name. Example: customer_id
	 * @param string $linked_property Linked object property name. Example: __customer
	 * @param mixed $value New ID value
	 * @param array $validation Optional db_field_validation() rules
	 * @return mixed Validated value for the set hook to assign to its backing value
	 */
	protected function set_fk_id(string $field, string $linked_property, mixed $value, array $validation = []): mixed
	{
		$value = $this->db_set_property_check($field, $value, $validation);
		if ($value != $this->{$field}) {
			$this->{$linked_property} = null;
		}
		return $value;
	}

	/**
	 * Sync a linked object assignment to its FK ID column, called from the linked object
	 * property's set hook. The PK field is read from the assigned object's own class, and
	 * is only synced when it has a real value, so assigning null or an unsaved object
	 * never changes the FK ID column.
	 * @param string $id_field FK column name to sync. Example: customer_id
	 * @param DBTable|null $value Linked object being assigned
	 * @return DBTable|null Value for the set hook to assign to its backing value
	 */
	protected function sync_linked_fk(string $id_field, ?DBTable $value): ?DBTable
	{
		if ($value !== null) {
			$pk_field = $value::$_table['pk_field'];
			if (!empty($value->{$pk_field}) && $value->{$pk_field} != $this->{$id_field}) {
				// This also clears the linked property's backing value via set_fk_id(),
				// but the set hook that called us re-assigns it right after we return
				$this->{$id_field} = $value->{$pk_field};
			}
		}
		return $value;
	}

	/**
	 * Perform DB validation on provided value. Ensures values are within required range and invalid characters are replaced
	 * TODO: Maybe move to DB object
	 * @param mixed $value
	 * @param array{string?:array{length?: int, trim?:bool, html?:bool, empty_to_null?:bool}, int?:array{min?: float, max?: float}, float?:array{min?: float, max?: float, precision?: int}, money?:array{min?: float, max?: float, precision?: int}, bool?:array{true: string}, timestamp?:true, enum?:class-string<\BackedEnum>, json?:true|array{remove_empty:bool}} $validation
	 * @return mixed
	 * @throws \Exception If input value cannot be sanitized and used at all
	 */
	protected function db_field_validation(mixed $value, array $validation): mixed
	{
		// TODO: Consider more logic for NULL values
		// Procesing below will convert it to empty string which causes other issues
		if (is_null($value)) {
			return null;
		}
		if (!empty($validation['string'])) {
			if (!empty($validation['string']['length'])) {
				if (!empty($validation['string']['trim'])) {
					$value = trim($value);
				}
				//TODO: Log warning if over max length
				$value = substr($value, 0, $validation['string']['length']);
				//TODO: Strip/Replace an invalid chars that cannot be saved to the DB
			}
			// Keep only safe HTML tags and attributes
			if (!empty($validation['string']['html'])) {
				$value = Html::sanitize_html($value);
			}
			if (!empty($validation['string']['empty_to_null']) && empty($value)) {
				$value = null;
			}
		}
		elseif (!empty($validation['int'])) {
			if (!is_int($value)) {
				$value = intval($value);
			}
			//TODO: Log warning if under/over min/max value
			if (isset($validation['int']['min']) && $value < $validation['int']['min']) {
				$value = $validation['int']['min'];
			}
			elseif (isset($validation['int']['max']) && $value > $validation['int']['max']) {
				$value = $validation['int']['max'];
			}
		}
		elseif (!empty($validation['float'])) {
			$value = floatval($value);

			//TODO: Log warning if under/over min/max value
			if (isset($validation['float']['min']) && $value < $validation['float']['min']) {
				$value = $validation['float']['min'];
			}
			elseif (isset($validation['float']['max']) && $value > $validation['float']['max']) {
				$value = $validation['float']['max'];
			}
			elseif (isset($validation['float']['precision'])) {
				$value = round($value, $validation['float']['precision']);
			}
		}
		elseif (!empty($validation['money'])) {
			//TODO: Log warning if under/over min/max value
			if (isset($validation['money']['min']) && $value < $validation['money']['min']) {
				$value = $validation['money']['min'];
			}
			elseif (isset($validation['money']['max']) && $value > $validation['money']['max']) {
				$value = $validation['money']['max'];
			}

			$value = Money::to_string($value, $validation['money']['precision'] ?? 2);
		}
		elseif (isset($validation['bool'])) {
			$value = ($value === true || strtoupper($value) === strtoupper($validation['bool']['true'] ?? 'Y'));
		}
		elseif (!empty($validation['timestamp'])) {
			// Accept NOW() as valid timestamp, otherwise format it
			if ($value != 'NOW()') {
				// Ensure proper date/time format
				if (!is_numeric($value)) {
					$value = strtotime($value);
				}
				// MySQL DATETIME/TIMESTAMP columns reject ISO 8601 ('c' format includes a
				// "T" separator and timezone offset), so use a bare MySQL-native format here.
				// DB::connect() sets the session time_zone to date_default_timezone_get(), so
				// this must use date() (not gmdate()) to match what MySQL will assume the
				// un-suffixed string means.
				$value = date('Y-m-d H:i:s', $value);
			}
		}
		elseif (!empty($validation['enum'])) {
			// Ensure value is allowed by enum
			if ($validation['enum']::tryFrom($value) === null) {
				//TODO: Log warning if invalid value

				throw new \Exception('Invalid ENUM value: '.$value, 99);
			}

		}
		elseif (!empty($validation['json'])) {
			// Remove empty keys (NULL and empty string)
			if (!empty($validation['json']['remove_empty'])) {
				if (is_string($value)) {
					$value = json_decode($value, true);
				}
				foreach ($value as $field => $val) {
					if (is_null($val) || $val === '') {
						unset($value[$field]);
					}
				}
			}
			
			if (is_array($value)) {
				$value = json_encode($value);
			}
			elseif (is_string($value)) {
				if (!json_validate($value)) {
					//TODO: Log warning if invalid value
					
					throw new \Exception('Invalid JSON value: '.$value, 99);
				}
			}
		}

		return $value;
	}

	/**
	 * Populate and return this object from an array of properties
	 * @param array<string, mixed> $array
	 * @param bool $populate_fields_changed Can be disabled when loading directly from DB
	 * @return static|null
	 */
	public static function populate_from_array($array, $populate_fields_changed = true): static|null
	{
		$db_columns = self::list_db_columns();
		// Special result only used for Full Text Search
		$db_columns[] = '_fts_match';

		$object = new static();
		
		if (!$populate_fields_changed) {
			$object->_loading_data = true;
		}

		foreach ($db_columns as $column) {
			if (array_key_exists($column, $array)) {
				// Mark loaded before assigning: a lazy field's own set hook reads the current value
				// to track changes, and if it isn't marked loaded yet that read lazy-loads it from
				// the DB right in the middle of populating it here
				$object->_loaded_fields[$column] = true;
				$object->{$column} = $array[$column];
			}
		}

		// Gets linked tables and populates linked objects if data is provided for them
		$linked_tables = self::list_linked_tables();
		if (!empty($linked_tables)) {
			foreach ($linked_tables as $table_property) {
				$table_name = ltrim($table_property, '_');
				if (isset($array[$table_name])) {
					try {
						$rp = new \ReflectionProperty(static::class, $table_property);
					}
					catch (\ReflectionException $e) {
						Log::error("DBTable::load_from_array failed. Reflection Property type lookup failed for ".static::class." property $table_property");
						return null;
					}
					$rp_type = $rp->getType();
					if (!$rp_type instanceof \ReflectionNamedType) {
						Log::error("DBTable::load_from_array failed. Property $table_property on ".static::class." has no single named type.");
						return null;
					}
					/** @var class-string<DBTable> $type Specific DBTable Object type. Example: Address */
					$type = $rp_type->getName();

					// Load linked object from array
					if (is_array($array[$table_name])) {
						$object->{$table_property} = $type::populate_from_array($array[$table_name]);
					} // Load linked object from object
					elseif ($array[$table_name] instanceof $type) {
						$object->{$table_property} = $array[$table_name];
					}
				}
			}
		}

		if (!$populate_fields_changed) {
			$object->_loading_data = false;
			$object->_fields_changed = [];
		}

		return $object;
	}
	
	/**
	 * Format DB record in format requested. Object or array
	 * @param mixed[]|null $records
	 * @param bool $single_record Is previous param a single record and not an array of records?
	 * @param int $load_rules DBTable::LOAD_XXX
	 * @return void
	 */
	protected static function format_for_return(?array &$records, bool $single_record, int $load_rules): void
	{
		$as_object = !($load_rules & DBTable::LOAD_AS_ARRAY);
		
		if (empty($records)) {
			// For array format return empty array
			if ($load_rules & DBTable::LOAD_AS_ARRAY) {
				$records = [];
			}
			// For object format return null when single, array when multiples
			else {
				$records = $single_record ? null : [];
			}
			return;
		}
		
		if ($single_record) {
			$records = [$records];
		}
		
		foreach ($records as &$record) {
			$pk = $record[static::$_table['pk_field']];
			$links = [];
			
			// Load incoming references - Actually just sets up an iterator for lazy loading as needed
			if (!empty(static::$_table['incoming']) && isset($pk)) {
				foreach (static::$_table['incoming'] as $linked_db_object => $remote_col) {
					/* @var class-string<D> $linked_db_object */
					//NOTICE: This is the correct format despite what the IDE might say: $linked_db_object::$_table['name']]
					$links[$as_object ? $linked_db_object : $linked_db_object::$_table['name']] = $linked_db_object::load_from_iterate([$remote_col => $pk], $linked_db_object::$_table['pk_field'].' ASC', load_rules:$load_rules);
				}
			}
			
			// Format as object
			if ($as_object) {
				$record = static::populate_from_array($record ?? [], false);
				$record->_load_rules = $load_rules;
				if (!empty($links)) {
					$record->_incoming_refs = $links;
				}
			}
			// Keep as array
			else {
				if (!empty($record) && !empty($links)) {
					$record['_incoming_refs'] = $links;
				}
			}
		}
		
		// Put back into starting format if this is a single record
		if ($single_record) {
			$records = $records[0];
		}
		
	}
	
	/**
	 * Load DB records with the given WHERE clauses. Returns an iterator that paginates for you!
	 * @param mixed[] $where Associative array of where parameters. Use :NULL: for 'IS NULL', :NOTNULL: for 'IS NOT NULL'. Pass an array of values for an IN clause. For an op other than equals, pass a value of: ['op' => '>', 'val' => $value]
	 * @param string $orderby Column(s) to order by, used for cursor based keyset pagination. Include ASC/DESC in each. Comma-separate for multiple columns. Example: order_pk ASC or priority DESC, order_pk ASC
	 * @param int $batch_size Behind the scenes, how many records we pull at once from the DB.
	 * @param int $load_rules DBTable::LOAD_XXX
	 * @return DBTableIterator<static>
	 * @throws \Exception Throws exception on conflicting $where and $orderby clauses
	 */
	public static function load_from_iterate(array $where, string $orderby, int $batch_size = 50, int $load_rules = DBTable::LOAD_DEFAULT): DBTableIterator
	{
		return new DBTableIterator(static::class, $where, $orderby, $batch_size, $load_rules);
	}
	
	/**
	 * Load DB records with the given WHERE clauses. Performs a direct single DB query
	 * @param mixed[] $where Associative array of where parameters. Use :NULL: for 'IS NULL', :NOTNULL: for 'IS NOT NULL'. Pass an array of values for an IN clause. For an op other than equals, pass a value of: ['op' => '>', 'val' => $value]
	 * @param string[]|string $orderby Optional list of columns to order by. Defaults to none. Include ASC, DESC in the name
	 * @param int|array<int,int>|null $limit Limit number or array. If 1: Only a single record will be returned, not an array. If array, first number is offset, second is limit. Can pass 3rd array value of TRUE to request a row count. Retrieve count with call to get_row_count()
	 * @param int $load_rules DBTable::LOAD_XXX
	 * @param array{sql:string, params:mixed[]}|null $raw_where Optional raw SQL WHERE fragment ANDed onto $where. Used by DBTableIterator for multi-column keyset pagination
	 * @return static[]|static|array|null
	 */
	public static function load_from(array $where, array|string $orderby = '', int|array|null $limit = null, int $load_rules = DBTable::LOAD_DEFAULT, ?array $raw_where = null): mixed
	{
		// Filter $fields to only include keys that match DB column names
		$search_fields = array_intersect_key($where, array_flip(static::list_db_columns()));
		// No valid fields to search
		if (empty($search_fields) && !empty($where)) {
			// Set proper blank return type
			Log::error("FIXME: Called ".static::$_table['name']." load_from() with no valid fields.", array_keys($where));
			return ($load_rules & DBTable::LOAD_AS_ARRAY) ? [] : ($limit === 1 ? null : []);
		}
		
		// An empty list can never match any row (SQL "IN ()"), so skip the query instead of letting it fail
		$empty_list_fields = array_keys(array_filter($search_fields, fn ($value) => is_array($value) && empty($value)));
		if (!empty($empty_list_fields)) {
			if (!($load_rules & DBTable::WHERE_OR)) {
				return ($load_rules & DBTable::LOAD_AS_ARRAY) ? [] : ($limit === 1 ? null : []);
			}
			$search_fields = array_diff_key($search_fields, array_flip($empty_list_fields));
			if (empty($search_fields)) {
				return ($load_rules & DBTable::LOAD_AS_ARRAY) ? [] : ($limit === 1 ? null : []);
			}
		}
		
		$records = null;
		$key = null;

		if (!empty(static::$_auto_cache)) {
			// Save key so we can save result to cache
			$key = static::get_query_cache_key($where, $orderby, $limit, $load_rules, $raw_where);
			// Load from cache if not blocked from doing so
			if (!($load_rules & DBTable::DISALLOW_CACHE)) {
				$records = MemCache::get($key);
				//	if(!empty($records)) {
				//		Log::info("Loaded DB record from hash cache " . static::$_table['name']);
				//	}
			}
		}
		
		if ($records === null || $records === false) {
			if ($load_rules & DBTable::LOG_QUERY) {
				DB::instance()->log_next();
			}
			
			$where_and = true;
			if ($load_rules & DBTable::WHERE_OR) {
				$where_and = false;
			}
			
			$records = DB::instance()->select(
				static::$_table['name'],
				$search_fields,
				static::get_load_columns($load_rules),
				$orderby,
				$limit,
				where_and: $where_and,
				raw_where: $raw_where
			);
			//TODO: Consider caching even if empty
			if (!empty(static::$_auto_cache) && !empty($records)) {
				MemCache::save($key, $records, static::$_auto_cache);
			}
		}
		
		static::format_for_return($records, ($limit === 1), $load_rules);
		
		return $records;
	}

	/**
	 * Load a DB record with the given primary PK
	 * //TODO: exception handling
	 * TODO: Add eager_loading option to auto-load linked objects from IDs. But add safety check to avoid infinite loops. EG. Customer links to Address links to Customer, etc
	 * @param int|string $pk
	 * @param int $load_rules DBTable::LOAD_XXX
	 * @return static|array|null
	 */
	public static function load_from_pk(int|string $pk, int $load_rules = DBTable::LOAD_DEFAULT): static|array|null
	{
		$record = null;
		$key = null;

		if (!empty(static::$_auto_cache)) {
			// Save key so we can save result to cache
			$cacheable_load_rules = self::get_cacheable_load_rules($load_rules);
			$key = 'dbautocache_'.static::$_table['name'].'_pk'.$pk.'_'.$cacheable_load_rules;
			// Load from cache if not blocked from doing so
			if (!($load_rules & DBTable::DISALLOW_CACHE)) {
				$record = MemCache::get($key);
				//	if(!empty($record)) {
				//		Log::info("Loaded DB record from cache ".static::$_table['name'].$pk, $record);
				//	}
			}
		}
		
		if ($record === null || $record === false) {
			// Select the record from DB
			$record = DB::instance()->select(static::$_table['name'], [
				static::$_table['pk_field'] => $pk
			], static::get_load_columns($load_rules), limit: 1);
			//TODO: Consider caching even if empty
			if (!empty(static::$_auto_cache) && !empty($record)) {
				MemCache::save($key, $record, static::$_auto_cache);
			}
		}
		
		static::format_for_return($record, true, $load_rules);
		return $record;
	}

	/**
	 * Load several DB records with the given primary PKs
	 * //TODO: exception handling
	 * @param int[]|string[] $pks
	 * @param int $load_rules DBTable::LOAD_XXX
	 * @return static[]|array[] Array of objects, or array of rows from DB if $as_object==false
	 */
	public static function load_from_pks(array $pks, int $load_rules = DBTable::LOAD_DEFAULT): array
	{
		// TODO: Consider batching and implementing an iterator to automatically get next batch
		
		// Select the record from DB
		$records = DB::instance()->select(static::$_table['name'], [
			static::$_table['pk_field'] => $pks
		], static::get_load_columns($load_rules));

		static::format_for_return($records, false, $load_rules);
		
		return $records;
	}
	
	/**
	 * Perform a count of total records for this where query
	 * @param mixed[] $where Associative array of where parameters. Use :NULL: for 'IS NULL', :NOTNULL: for 'IS NOT NULL'. Pass an array of values for an IN clause. For an op other than equals, pass a value of: ['op' => '>', 'val' => $value]
	 * @param int $load_rules DBTable::LOG_QUERY, DBTable::WHERE_OR
	 * @return int
	 */
	public static function count(array $where, int $load_rules = DBTable::LOAD_DEFAULT): int
	{
		// Filter $fields to only include keys that match DB column names
		$search_fields = array_intersect_key($where, array_flip(static::list_db_columns()));
		// No valid fields to search
		if (empty($search_fields)) {
			// Set proper blank return type
			Log::error("FIXME: Called ".static::$_table['name']." load_from() with no valid fields.", array_keys($where));
			return 0;
		}
		
		
		if ($load_rules & DBTable::LOG_QUERY) {
			DB::instance()->log_next();
		}
		
		$where_and = true;
		if ($load_rules & DBTable::WHERE_OR) {
			$where_and = false;
		}
		
		$records = DB::instance()->select(
			static::$_table['name'],
			$search_fields,
			['COUNT(*) as count'],
			'',
			null,
			where_and: $where_and
		);
		
		return $records['count'] ?? 0;
	}

	/**
	 * Search a single DB record with the given field/values
	 * @param array<string, mixed> $fields Field name as key. Field value as value
	 * @param int $load_rules DBTable::LOAD_XXX
	 * @return static|array|null
	 */
	public static function select_one(array $fields, int $load_rules = DBTable::LOAD_DEFAULT): mixed
	{
		return static::load_from(
			$fields,
			limit: 1,
			load_rules: $load_rules
		);
	}

	/**
	 * Save changes or new record to DB
	 * //TODO: exception handling
	 * @param $on_dup_update_key array	Ignores duplicates by updating key to itself. Any column or array of columns will work
	 * @return string|int|false Returns record PK on success, FALSE on failure
	 */
	public function save(array $on_dup_update_key = []): int|string|false
	{
		if (!empty($this->_fields_changed)) {
			// Build the INSERT/UPDATE fields
			$updates = [];
			//TODO: Change to debug line
			Log::info(static::$_table['name'].' PK ('.($this->{static::$_table['pk_field']} ?? '').') Fields Changed list', $this->_fields_changed);
			foreach ($this->_fields_changed as $field_name => $val) {
				$updates[$field_name] = $this->{$field_name};
			}
			
			// If PK is set (and non-zero), this is an update
			if (!empty($this->{static::$_table['pk_field']})) {
				$result = DB::instance()->update(
					static::$_table['name'],
					[static::$_table['pk_field'] => $this->{static::$_table['pk_field']}],
					$updates
				);
				
				if (!empty($result)) {
					$this->_fields_changed = [];
					
					static::clear_pk_cache($this->{static::$_table['pk_field']});
					
					return $this->{static::$_table['pk_field']};
				}
				
				return false;
			}
			// Otherwise we're Inserting a new record
			else {
				$result = DB::instance()->insert(
					static::$_table['name'],
					$updates,
					$on_dup_update_key
				);
				
				if (!empty($result)) {
					// Get the Inserted record's ID/PK
					$this->{static::$_table['pk_field']} = DB::instance()->get_last_insert_id();
					// Reset _fields_changed
					$this->_fields_changed = [];
				}
			}
		}

		//TODO: If any __ properties (aka linked tables) are set but the PK isn't, create and link them
		// Note: We're handling this in /src/Logic objects and that's probably best.
		

		return $this->{static::$_table['pk_field']} ?? false;
	}
	
	/**
	 * Delete current record from the DB
	 * TODO: Auto delete incoming linked tables
	 * @return bool
	 */
	public function delete(): bool
	{
		// Cannot delete if PK not loaded
		if (empty($this->{static::$_table['pk_field']})) {
			return false;
		}
		
		$result = DB::instance()->delete(static::$_table['name'], [
			static::$_table['pk_field'] => $this->{static::$_table['pk_field']}
		], true);
		
		if (!empty($result)) {
			static::clear_pk_cache($this->{static::$_table['pk_field']});
		}
		
		return $result;
	}
	
	/**
	 * Delete the auto-cache entries held for one or more of this table's records.
	 * save() and delete() call this automatically. Call it directly after writing columns outside of
	 * those methods (EG. a bulk UPDATE) so stale rows aren't served from cache.
	 * Does nothing for tables that don't set $_auto_cache.
	 * @param int|string ...$pks Primary keys to clear
	 * @return void
	 */
	public static function clear_pk_cache(int|string ...$pks): void
	{
		if (empty(static::$_auto_cache) || empty($pks)) {
			return;
		}
		
		$keys = [];
		$mask = self::$_cache_mask;
		foreach ($pks as $pk) {
			$base_key = 'dbautocache_' . static::$_table['name'] . '_pk' . $pk . '_';
			$keys[] = $base_key.'0'; // Handle 0 case (LOAD_DEFAULT)
			
			for ($rules = $mask; $rules > 0; $rules = ($rules - 1) & $mask) {
				$keys[] = $base_key . $rules;
			}
		}
		
		MemCache::delete($keys);
	}

	/**
	 * Build the auto-cache key for a load_from() query
	 * @param mixed[] $where
	 * @param array|string $orderby
	 * @param int|array<int,int>|null $limit
	 * @param int $load_rules
	 * @param array{sql:string, params:mixed[]}|null $raw_where
	 * @return string
	 */
	public static function get_query_cache_key(array $where, array|string $orderby = '', int|array|null $limit = null, int $load_rules = self::LOAD_DEFAULT, ?array $raw_where = null): string
	{
		$cacheable_load_rules = self::get_cacheable_load_rules($load_rules);
		return 'dbautocache_' . static::$_table['name'] . '_hash' . hash('md4', serialize($where) . (is_string($orderby) ? $orderby : serialize($orderby)) . serialize($limit) . $cacheable_load_rules . serialize($raw_where));
	}

	/**
	 * Delete the auto-cache entries held for a load_from() query matching the given where/order/limit arguments.
	 * Clears across all cacheable load_rules mask permutations.
	 * Does nothing for tables that don't set $_auto_cache.
	 *
	 * Example: Address::clear_query_cache(['customer_id' => $customer_id]);
	 *
	 * @param mixed[] $where Associative array of where parameters matching load_from()
	 * @param string[]|string $orderby Optional list of columns to order by
	 * @param int|array<int,int>|null $limit Limit number or array
	 * @param array{sql:string, params:mixed[]}|null $raw_where Optional raw SQL WHERE fragment
	 * @return void
	 */
	public static function clear_query_cache(array $where, array|string $orderby = '', int|array|null $limit = null, ?array $raw_where = null): void
	{
		if (empty(static::$_auto_cache) || empty($where)) {
			return;
		}

		$keys = [];
		$mask = self::$_cache_mask;
		$keys[] = static::get_query_cache_key($where, $orderby, $limit, 0, $raw_where);

		for ($rules = $mask; $rules > 0; $rules = ($rules - 1) & $mask) {
			$keys[] = static::get_query_cache_key($where, $orderby, $limit, $rules, $raw_where);
		}

		MemCache::delete(array_values(array_unique($keys)));
	}

	/**
	 * Alias for clear_query_cache()
	 * @param mixed[] $where
	 * @param array|string $orderby
	 * @param int|array<int,int>|null $limit
	 * @param array{sql:string, params:mixed[]}|null $raw_where
	 * @return void
	 */
	public static function clear_where_cache(array $where, array|string $orderby = '', int|array|null $limit = null, ?array $raw_where = null): void
	{
		static::clear_query_cache($where, $orderby, $limit, $raw_where);
	}
	
	/**
	 * Dump the current object DB fields to an array
	 * @return array
	 */
	public function to_array()
	{
		$result = [];
		$fields = static::list_db_columns();
		foreach ($fields as $field) {
			$result[$field] = $this->{$field};
		}
		return $result;
	}

	/**
	 * Overwrite this to tell the DataGenerator how to generate sample data for each field
	 * Types: string, int, int_small, float, timestamp, phone, email, name, words, address, city, state, country
	 * @return array<string, array{type:string, params?:mixed}> Example: ['name' => ['type' => 'string', 'params' => [150]]]. Params will be passed to the DataGenerator::type() function
	 */
	public static function sample_data_types(): array
	{
		return [];
	}
	
	/**
	 * Cloning a DBTable object unsets the PK field
	 * @return void
	 */
	public function __clone()
	{
		// Ensure lazy fields are loaded before we remove the PK
		if (!empty(static::$_table['lazy'])) {
			foreach (static::$_table['lazy'] as $field) {
				if (!$this->is_field_loaded($field)) {
					$this->load_lazy($field);
				}
			}
		}
		
		$this->{static::$_table['pk_field']} = null;
		// Mark all columns as changed
		foreach (static::list_db_columns() as $column) {
			if ($column != static::$_table['pk_field']) {
				$this->_fields_changed[$column] = true;
			}
		}
	}

	/**
	 * Filters the load rules to only include those that affect caching
	 * @param int $load_rules
	 * @return int
	 */
	private static function get_cacheable_load_rules(int $load_rules): int
	{
		return $load_rules & self::$_cache_mask;
	}

	/**
	 * Determine which columns to load based on load rules and lazy field settings
	 * @param int $load_rules
	 * @return array
	 */
	protected static function get_load_columns(int $load_rules): array
	{
		// Load all fields
		if (($load_rules & self::LOAD_LAZY_FIELDS) || empty(static::$_table['lazy'])) {
			return [];
		}
		// Load all non-lazy loaded fields
		return array_diff(static::list_db_columns(), static::$_table['lazy']);
	}

	/**
	 * Load a lazy loaded field for this object
	 * @param string $field
	 * @return void
	 */
	protected function load_lazy(string $field): void
	{
		if (empty($this->{static::$_table['pk_field']})) {
			$this->_loaded_fields[$field] = true;
			return;
		}
		
		if (!empty($this->_loaded_fields[$field])) {
			return;
		}

		$record = DB::instance()->select(
			static::$_table['name'],
			[static::$_table['pk_field'] => $this->{static::$_table['pk_field']}],
			[$field],
			limit: 1
		);
		
		Log::debug('Lazy loaded '.static::$_table['name'].".{$field} with pk ".$this->{static::$_table['pk_field']});
		
		$this->_loading_data = true;
		//TODO: Detect if there's a DB error and leave field NULL and don't set _loaded_fields.
		$this->{$field} = $record;
		$this->_loaded_fields[$field] = true;
		
		$this->_loading_data = false;
		
		// Ensure it's not marked as changed
		if (isset($this->_fields_changed[$field])) {
			unset($this->_fields_changed[$field]);
		}
	}
	
	/**
	 * Check if a specific field has been loaded from the DB
	 * @param string $field
	 * @return bool
	 */
	public function is_field_loaded(string $field): bool
	{
		return !empty($this->_loaded_fields[$field]);
	}

}
