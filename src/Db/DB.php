<?php

namespace PSP\Db;

use PDO;
use PDOException;
use PSP\App\Config;
use PSP\App\Globals;
use PSP\Log\Log;
use PSP\Util\Crypt;

class DB
{
	/** @var array<string, DB> $instance */
	private static array $instance = [];
	private ?PDO $connection;

	/**
	 * @var string[] List of supported operations in WHERE clause. "<>" is intentionally not supported
	 */
	public static array $valid_ops = ['=','!=','<','>','<=','>=','LIKE','NOT LIKE', 'MATCH'/*, 'IN','NOT IN'*/];
	/**
	 * @var array{function_name:string, params:mixed[]}|array{}
	 */
	private array $last_query;
	private bool $log_next_query = false;
	private ?string $error;
	public bool $fallback_to_primary_if_empty = false;
	private \PDOStatement|null $result;
	public bool $connected;
	private bool $in_transaction = false;
	
	/**
	 * Private constructor. Use DB::instance() instead
	 */
	private function __construct(private bool $replica = false)
	{
		$this->connect();
	}
	
	/**
	 * Perform DB connection
	 * @return void
	 */
	public function connect(): void
	{
		if ($this->in_transaction) {
			Log::critical("Database connection lost or disconnected during an active transaction! Any further operations in this transaction will fail.");
		}
		$this->in_transaction = false;

		// Detect server's timezone
		$timezone = date_default_timezone_get();
		$dt = new \DateTime('now', new \DateTimeZone($timezone));
		
		try {
			$dsn = "mysql:host=".($this->replica ? Config::get('db')['replica_host'] : Config::get('db')['host'])
				.";dbname=".Config::get('db')['name']
				.";charset=utf8mb4";
			$this->connection = new PDO(
				$dsn,
				Config::get('db')['username'],
				Config::get('db')['password'],
				[
					PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
					PDO::ATTR_PERSISTENT => true, // Optional: Enables persistent connection
					// Enable auto-conversion between server's timezone and DB's timezone
					\Pdo\Mysql::ATTR_INIT_COMMAND => "SET time_zone = '".$dt->format("P")."'",
				]
			);
			$this->connected = true;
		}
		catch (PDOException $e) {
			$this->connected = false;
			throw new PDOException('Database connection failed: ' . $e->getMessage(), (int)$e->getCode(), $e);
		}
	}

	/**
	 * Get a singleton instance of the DB object
	 * @param bool $replica Connect to a replica DB
	 * @return DB
	 */
	public static function instance(bool $replica = false): DB
	{
		//TODO: Logic to check when last write was and return a primary if it was too recent
		//TODO: Fallback if connection to replica fails
		
		$type = $replica ? 'replica' : 'primary';
		if (!isset(self::$instance[$type])) {
			self::$instance[$type] = new self($replica);
		}
		//Reconnect if necessary
		elseif (!isset(self::$instance[$type]->connection) || !self::$instance[$type]->connected) {
			self::$instance[$type]->connect();
		}
		return self::$instance[$type];
	}

	/**
	 * Get the underlying PDO connection
	 * @return PDO
	 */
	public function getConnection(): ?PDO
	{
		return $this->connection;
	}
	
	/**
	 * Release the current DB connection
	 * @return void
	 */
	public function disconnect(): void
	{
		if ($this->in_transaction) {
			Log::error("FIXME: Database disconnect() called while a transaction was active!");
		}
		$this->connection = null;
		$this->connected = false;
		$this->in_transaction = false;
	}
	
	/**
	 * Initiates a transaction.
	 * Turns off autocommit mode. While autocommit mode is turned off,
	 * changes made to the database via the PDO object instance are not committed
	 * until you end the transaction by calling commit().
	 * Calling rollBack() will roll back all changes to the database and return the
	 * connection to autocommit mode.
	 *
	 * @return bool TRUE on success or FALSE on failure.
	 * @throws PDOException If there is already a transaction active.
	 */
	public function beginTransaction(): bool
	{
		if (!$this->connected || !isset($this->connection)) {
			$this->connect();
		}
		$this->in_transaction = $this->connection->beginTransaction();
		return $this->in_transaction;
	}

	/**
	 * Commits a transaction.
	 * Turns autocommit mode back on and makes all changes made to the database
	 * since the start of the transaction permanent.
	 *
	 * @return bool TRUE on success or FALSE on failure.
	 */
	public function commit(): bool
	{
		if (!$this->connected || !isset($this->connection)) {
			$this->connect();
		}

		// Use PDO's inTransaction() as the authoritative source, consistent with rollBack()
		if (!$this->connection->inTransaction()) {
			Log::error('FIXME: DB commit() called while there is no active transaction');
			$this->in_transaction = false;
			return false;
		}

		$success = $this->connection->commit();
		$this->in_transaction = false;
		return $success;
	}

	/**
	 * Rolls back the current transaction.
	 * Turns autocommit mode back on and reverts all changes made to the database
	 * since the start of the transaction.
	 *
	 * @return bool TRUE on success or FALSE on failure.
	 * @throws PDOException If there is no active transaction.
	 */
	public function rollBack(): bool
	{
		if (!$this->connected || !isset($this->connection)) {
			$this->connect();
		}
		// Check if a transaction is active before attempting rollback
		// This prevents an exception if rollBack is called unnecessarily
		if ($this->connection->inTransaction()) {
			$success = $this->connection->rollBack();
			$this->in_transaction = false;
			return $success;
		}
		$this->in_transaction = false;
		return true;
	}

	/**
	 * Checks if the database connection is currently in a transaction.
	 *
	 * @return bool Returns true if the connection is in a transaction, otherwise false.
	 */
	public function inTransaction(): bool
	{
		if (!$this->connected || !isset($this->connection)) {
			$this->connect();
		}
		return $this->in_transaction && $this->connection->inTransaction();
	}
	
	/**
	 * Run named query, returning result. We expect the parameters and expected type values to have their keys as the parameter name.
	 * @param string $query_with_placeholders
	 * @param mixed[] $arr_parameters Format: ['name' => $val, 'name2' => $val2]
	 * @param mixed[] $arr_expected_types Format: ['name' => PDO::PARAM_, 'name2' => PDO::PARAM_]
	 * @param bool $return_result
	 * @return bool|array|null
	 */
	public function run_named_query(
		string $query_with_placeholders,
		array $arr_parameters,
		array $arr_expected_types = [],
		bool $return_result = false
	) {
		$this->set_query_db_type();
		
		$this->last_query = [
			'function_name' => 'run_named_query',
			'params' => [$query_with_placeholders, $arr_parameters,
				$arr_expected_types, $return_result]
		];
		
		$is_select = stripos($query_with_placeholders, 'SELECT') === 0;
		if ($this->replica && !$is_select && !$return_result) {
			throw new \PDOException('Only SELECT allowed from a read replica');
		}
		
		if ($this->log_next_query) {
			Log::info($query_with_placeholders, $arr_parameters, 1);
			$this->log_next_query = false;
		}
		
		$this->error = null;
		if (!$this->connected || !isset($this->connection)) {
			$this->connect();
		}
		$dataset_container = $this->connection->prepare($query_with_placeholders);
		
		$arr_parameter_names = !empty($arr_parameters) ? array_keys($arr_parameters) : [];
		
		foreach ($arr_parameter_names as $parameter_name) {
			$param = $arr_parameters[$parameter_name];
			$expected_type = $arr_expected_types[$parameter_name] ?? null;
			
			// Make sure params are not arrays!
			if (is_array($param)) {
				Log::error('FIXME: Invalid named-param passed to PDO query. Array not allowed: '.$parameter_name, $param);
			}
			
			// if we didn't pass an expected type, we don't need one
			if (!is_null($expected_type)) {
				$success = $dataset_container->bindValue(
					":".$parameter_name,
					$param,
					$expected_type
				);
			}
			else {
				$success = $dataset_container->bindValue(
					":".$parameter_name,
					$param
				);
			}
			
			if (!$success) {
				$this->result = null;
				$this->error = self::get_fail_message(
					$query_with_placeholders,
					$arr_parameters
				) . " - bind failed for $parameter_name.";
				Log::warning($this->error);
				return false;
			}
		}
		
		$start_time = microtime(true);
		Globals::instance()->num_db_calls++;
		try {
			$final_success = $dataset_container->execute();
		}
		catch (PDOException $e) {
			// If deadlock, Sleep and try SECOND time
			if ($this->can_retry_deadlock($e)) {
				usleep(Crypt::rand_int(2, 7) * 100000); // .X seconds
				try {
					$final_success = $dataset_container->execute();
				}
				catch (\Exception $e) {
					// If deadlock, Sleep and try THIRD time
					if ($this->can_retry_deadlock($e)) {
						usleep(Crypt::rand_int(1, 5) * 100000); // .X seconds
						try {
							$final_success = $dataset_container->execute();
						}
						catch (\Exception $e) {
							$this->error = $e->getMessage();
						}
					}
					else {
						$this->error = $e->getMessage();
					}
				}
			}
			else {
				$this->error = $e->getMessage();
			}
		}
		
		if (!empty($final_success)) {
			$this->result = $dataset_container;
			if ($return_result) {
				return $this->get_all_rows();
			}
			else {
				if (!$is_select) {
					Globals::instance()->last_db_write_time = microtime(true);
				}
				return true;
			}
		}
		else {
			$this->result = null;
			$this->log_mysql_error(
				$this->error,
				$query_with_placeholders,
				$arr_parameters
			);
			return false;
		}
	}
	
	/**
	 * Run unnamed query, returning result.
	 * @param string $query_with_placeholders	Query as a string with placeholders
	 * @param mixed[] $arr_parameters			Array of unnamed parameters to place in query
	 * @param bool $return_result			Optional boolean. Return results on completion? If false, a success boolean is returned. Default false.
	 * @return array|bool	Array of results if $return_result==true. True/False otherwise identifying query success
	 */
	public function run_unnamed_query(string $query_with_placeholders, array $arr_parameters, bool $return_result = false)
	{
		//$arr_parameters might have improper nested arrays that will raise PHP warnings when sent to $dataset_container->execute()
		$arr_parameters = $this->flattenArray($arr_parameters);
		
		$this->set_query_db_type();
		
		$this->last_query = [
			'function_name' => 'run_unnamed_query',
			'params' => [$query_with_placeholders, $arr_parameters,
				$return_result]
		];
		
		$this->error = null;
		if (!$this->connected || !isset($this->connection)) {
			$this->connect();
		}
		if (empty($arr_parameters)) {
			$arr_parameters = [];
		}
		// Make sure params are not arrays!
		$count = 0;
		foreach ($arr_parameters as $param) {
			if (is_array($param)) {
				Log::error('Invalid unnamed-param passed to PDO query. Array not allowed: '.$count, $param);
				break;
			}
			$count++;
		}
		
		$is_select = stripos($query_with_placeholders, 'SELECT') === 0;
		if ($this->replica && !$is_select && !$return_result) {
			throw new \PDOException('Only SELECT allowed from a read replica');
		}
		
		if ($this->log_next_query) {
			Log::info($query_with_placeholders, $arr_parameters);
			$this->log_next_query = false;
		}
		
		$dataset_container = $this->connection->prepare($query_with_placeholders);
		$start_time = microtime(true);
		Globals::instance()->num_db_calls++;
		try {
			$final_success = $dataset_container->execute(array_values($arr_parameters));
		}
		catch (PDOException $e) {
			// If deadlock, Sleep and try SECOND time
			if ($this->can_retry_deadlock($e)) {
				usleep(Crypt::rand_int(2, 7) * 100000); // .X seconds
				try {
					$final_success = $dataset_container->execute(array_values($arr_parameters));
				}
				catch (\Exception $e) {
					// If deadlock, Sleep and try THIRD time
					if ($this->can_retry_deadlock($e)) {
						usleep(Crypt::rand_int(1, 5) * 100000); // .X seconds
						try {
							$final_success = $dataset_container->execute(array_values($arr_parameters));
						}
						catch (\Exception $e) {
							$this->error = $e->getMessage();
						}
					}
					else {
						$this->error = $e->getMessage();
					}
				}
			}
			else {
				$this->error = $e->getMessage();
			}
		}
		
		if (!empty($final_success)) {
			$this->result = $dataset_container;
			if ($return_result) {
				return $this->get_all_rows();
			}
			else {
				if (!$is_select) {
					Globals::instance()->last_db_write_time = microtime(true);
				}
				return true;
			}
		}
		else {
			$this->result = null;
			$this->log_mysql_error(
				$this->error,
				$query_with_placeholders,
				$arr_parameters
			);
			return false;
		}
	}
	
	/**
	 * Run query containing no parameters
	 *
	 * @param string $query The query
	 * @param bool $return_result If true, we return the result rows; otherwise, default false
	 * @return bool|array True on success and false on failure unless $return_result
	 */
	public function run_static_query(string $query, bool $return_result = false)
	{
		$this->set_query_db_type();
		
		$this->last_query = [
			'function_name' => 'run_static_query',
			'params' => [$query, $return_result]
		];
		
		$is_select = stripos($query, 'SELECT') === 0;
		if ($this->replica && !$is_select && !$return_result) {
			throw new \PDOException('Only SELECT allowed from a read replica');
		}
		
		if ($this->log_next_query) {
			Log::info($query, stacktrace_skip:1);
			$this->log_next_query = false;
		}
		
		$this->error = null;
		if (!$this->connected || !isset($this->connection)) {
			$this->connect();
		}
		Globals::instance()->num_db_calls++;
		try {
			$attempted_query_result = $this->connection->query($query);
		}
		catch (PDOException $e) {
			// If deadlock, Sleep and try SECOND time
			if ($this->can_retry_deadlock($e)) {
				usleep(Crypt::rand_int(2, 7) * 100000); // .X seconds
				try {
					$attempted_query_result = $this->connection->query($query);
				}
				catch (\Exception $e) {
					// If deadlock, Sleep and try THIRD time
					if ($this->can_retry_deadlock($e)) {
						usleep(Crypt::rand_int(1, 5) * 100000); // .X seconds
						try {
							$attempted_query_result = $this->connection->query($query);
						}
						catch (\Exception $e) {
							$this->error = $e->getMessage();
						}
					}
					else {
						$this->error = $e->getMessage();
					}
				}
			}
			else {
				$this->error = $e->getMessage();
			}
		}
		
		if (!empty($attempted_query_result)) {
			$this->result = $attempted_query_result;
			if ($return_result) {
				return $this->get_all_rows();
			}
			else {
				if (!$is_select) {
					Globals::instance()->last_db_write_time = microtime(true);
				}
				return true;
			}
		}
		else {
			$this->result = null;
			$this->log_mysql_error($this->error, $query);
			return false;
		}
	}
	
	/**
	 * Insert function. Returns num rows affected or false for error. NOTICE: Can return 0 on a successful call if nothing updated.
	 * This function can insert NULL and NOW() values
	 * @param string $table_name
	 * @param array $arr_parameters Array/Value key pairs to insert, or a numeric array of them for multi-row insert. Can pass NOW() or NOW() +- N
	 * @param array $on_dup_update_key	Ignores duplicates by updating key to itself. Any column or array of columns will work
	 * @param boolean $debug	Perform debug logging
	 * @return mixed False if fail, num rows affected otherwise
	 */
	public function insert($table_name, $arr_parameters, $on_dup_update_key = [], $debug = false)
	{
		if ($this->replica) {
			throw new \PDOException('Do not call insert() from a read replica');
		}
		
		if (strpos($table_name, '.') === false) {
			$table_name = "`$table_name`";
		}
		
		$bind_parameters = [];
		
		// if we have something
		if (!empty($arr_parameters) && count($arr_parameters) > 0) {
			// Build query for single row insert - Single row inserts don't have to be numerically indexed
			if (!isset($arr_parameters[0]) || !is_array($arr_parameters[0])) {
				$col_placeholder = implode('`,`', array_keys($arr_parameters));
				
				$placeholders = [];
				foreach ($arr_parameters as $value) {
					if (!is_array($value) && preg_match('/^NOW\(\)(( ?)[+-]( ?)[0-9]+)?$/', $value ?? '') === 1) {
						$placeholders[] = $value;
					}
					else {
						$placeholders[] = '?';
						$bind_parameters[] = $value;
					}
				}
				$val_placeholder = implode(',', $placeholders);
				
				$qry = "INSERT INTO $table_name (`$col_placeholder`)"
					." VALUES ($val_placeholder)";
			}
			// Build query for multiple row insert
			else {
				//TODO: If inserting more than 50 rows, split into chunks of 50 and loop over each
				$columns = array_keys($arr_parameters[0]);
				$col_placeholder = implode('`,`', $columns);
				
				$row_placeholders = [];
				foreach ($arr_parameters as $row) {
					$current_row_placeholders = [];
					foreach ($columns as $column) {
						$value = $row[$column];
						if (!is_array($value) && preg_match('/^NOW\(\)(( ?)[+-]( ?)[0-9]+)?$/', $value ?? '') === 1) {
							$current_row_placeholders[] = $value;
						}
						else {
							$current_row_placeholders[] = '?';
							$bind_parameters[] = $value;
						}
					}
					$row_placeholders[] = implode(',', $current_row_placeholders);
				}
				
				$qry = "INSERT INTO $table_name (`$col_placeholder`)"
					." VALUES (".implode('),(', $row_placeholders).")";
			}
			// Accept array of column names to update on duplicate key
			if (!empty($on_dup_update_key)) {
				$qry .= ' ON DUPLICATE KEY UPDATE';
				foreach ($on_dup_update_key as $column) {
					$qry .= " $column = VALUES($column),";
				}
				// Trim off last comma
				$qry = substr($qry, 0, -1);
			}
			
			if ($debug) {
				$this->log_next();
			}
			
			// run
			if ($this->run_unnamed_query($qry, $bind_parameters, false)) {
				Globals::instance()->last_db_write_time = microtime(true);
				return $this->get_num_rows();
			}
			else {
				return false;
			}
		}
		else {
			if ($debug) {
				$this->log_next();
			}
			// Insert blank row
			if ($this->run_unnamed_query("INSERT INTO $table_name () VALUES()", [], false)) {
				return $this->get_num_rows();
			}
			else {
				return false;
			}
		}
	}
	
	/**
	 * Delete function. Returns pdo_statement object for parent function to use
	 * @param string $table_name
	 * @param array $arr_where_parameters Associative array of where parameters. Use :NULL: for 'IS NULL', :NOTNULL: for 'IS NOT NULL'. Pass an array of values for an IN clause. For an op other than equals, pass a value of: ['op' => '>', 'val' => $value]
	 * @param boolean $is_limit_1 Default false
	 * @param boolean $debug Default true
	 * @return boolean Success
	 */
	public function delete($table_name, $arr_where_parameters, $is_limit_1 = false, $debug = false)
	{
		if ($this->replica) {
			throw new \PDOException('Do not call delete() from a read replica');
		}
		
		if (!str_contains($table_name, '.')) {
			$table_name = "`$table_name`";
		}
		// if we have something
		$arr_parameters = [];
		if (count($arr_where_parameters) > 0) {
			// build the query
			$qry = "DELETE FROM $table_name WHERE ";
			
			foreach ($arr_where_parameters as $column_name => $value) {
				if (!is_array($value)) {
					if ($value === ':NULL:') {
						$conditionals[] = "$column_name IS NULL";
					}
					elseif ($value === ':NOTNULL:') {
						$conditionals[] = "$column_name IS NOT NULL";
					}
					else {
						$conditionals[] = "$column_name = ?";
						$arr_parameters[] = $value;
					}
				}
				elseif (!empty($value['op'])) {
					$conditionals[] = "$column_name {$value['op']} ?";
					$arr_parameters[] = $value['val'];
					
					if (!in_array($value['op'], self::$valid_ops)) {
						$qry .= implode(' AND ', $conditionals);
						$this->log_mysql_error("Passed invalid op to query: {$value['op']}", $qry, $arr_parameters);
						return false;
					}
				}
				else {
					$conditionals[] = "$column_name IN (". implode(',', array_fill(0, count($value), '?')).")";
					$arr_parameters = array_merge($arr_parameters, $value);
				}
			}
			
			$qry .= implode(' AND ', $conditionals);
			if ($is_limit_1) {
				$qry .= " LIMIT 1";
			}
			// run
			$result = $this->run_unnamed_query($qry, $arr_parameters, false);
			Globals::instance()->last_db_write_time = microtime(true);
			return $result;
		}
		else {
			$this->result = null;
			$this->error = "Delete from $table_name with conditionals ("
				.implode(',', $arr_where_parameters).") failed - nothing to be deleted.";
			return false;
		}
	}
	
	/**
	 * Build a simple select statement on the fly
	 * @param string $table_name				Table to select from
	 * @param mixed[] $arr_where_parameters		Associative array of where parameters. Use :NULL: for 'IS NULL', :NOTNULL: for 'IS NOT NULL'. Pass an array of values for an IN clause. For an op other than equals, pass a value of: ['op' => '>', 'val' => $value]<br>
	 * 											NOTE: When using the MATCH op, a "_fts_match" column will be included in the result
	 * @param string[]|string $arr_return_columns		Optional array or string list of columns to select/return. Defaults to selecting '*'
	 * @param string[]|string $arr_order_by_parameters	Optional list of columns to order by. Defaults to none. Include ASC, DESC in the name
	 * @param int|array<int,int>|null $limit			Optional limit number or array. If array, first number is offset, second is limit. Can pass 3rd array value of TRUE to request a row count. Retrieve count with call to get_row_count()
	 * @param bool $return_result				Optional. Return result true/false? If false, a success boolean is returned. Default true.
	 * @param bool $where_and					Optional. Use AND between WHERE true/false? If false, OR is used. Default true.
	 * @param string[] $group_by				Optional array of GROUP BY statements
	 * @param string $having					Optional HAVING statement as a string. (Leave out 'HAVING' at the beginning)
	 * @param array{sql:string, params:mixed[]}|null $raw_where	Optional raw SQL WHERE fragment (no leading "AND"/"WHERE") plus its bound params, ANDed onto $arr_where_parameters. Used for conditions that can't be expressed as a flat column=>value list, e.g. multi-column keyset pagination
	 * @return mixed	2-D Array of results. If $limit is passed as one, 1-D array will be returned. If $limit is 1 and only a single column is requested, that exact value will be returned. true/false if return not requested.
	 */
	public function select(
		string $table_name,
		array $arr_where_parameters = [],
		array|string $arr_return_columns = '*',
		array|string $arr_order_by_parameters = '',
		int|array|null $limit = null,
		bool $return_result = true,
		bool $where_and = true,
		array $group_by = [],
		string $having = '',
		?array $raw_where = null
	): mixed {
		$intval = null;
		// build query and arr_parameters
		$columns = '';
		$count_rows = '';
		$arr_parameters = [];
		if (empty($arr_return_columns)) {
			$columns .= "*";
		}
		else {
			if (!is_array($arr_return_columns)) {
				$columns .= $arr_return_columns;
			}
			else {
				$columns .= implode(", ", $arr_return_columns);
			}
		}
		// Check for match statement
		// If so, add _fts_match column to result
		$match_statements = [];
		foreach ($arr_where_parameters as $column_name => $value) {
			if (isset($value['op']) && $value['op'] == 'MATCH') {
				$match_statements[] = "MATCH($column_name) AGAINST(?)";
				$arr_parameters[] = $value['val'];
			}
		}
		if (!empty($match_statements)) {
			if (count($match_statements) == 1) {
				$columns .= ', (' . implode(" + ", $match_statements) . ')';
			}
			// If multiple matches, divide to keep the total between 0 and 1
			else {
				$columns .= ', (' . implode(" + ", $match_statements) . ') / '.count($match_statements);
			}
			
			$columns .= ' as _fts_match';
		}
		unset($match_statements);
		
		if (strpos($table_name, '.') === false) {
			$table_name = "`$table_name`";
		}
		
		// Request counting of rows if 3rd param of $limit is exactly TRUE
		//TODO: WARNING this is deprecated. Use a second SELECT COUNT(*) query instead
		if (is_array($limit) && isset($limit[2]) && $limit[2] === true) {
			$count_rows = 'SQL_CALC_FOUND_ROWS';
		}
		
		$qry = "SELECT $count_rows $columns FROM $table_name";
		
		$has_raw_where = !empty($raw_where['sql']);

		if (count($arr_where_parameters) || $has_raw_where) {
			$qry .= " WHERE";
		}
		if (count($arr_where_parameters)) {
			$where_sql = '';
			$where_and_kw = $where_and ? 'AND' : ' OR'; // Don't remove the space before OR. It's needed because of our bad code.
			foreach ($arr_where_parameters as $column_name => $value) {
				if (!is_array($value)) {
					if ($value === ':NULL:') {
						$where_sql .= " $column_name IS NULL $where_and_kw";
					}
					elseif ($value === ':NOTNULL:') {
						$where_sql .= " $column_name IS NOT NULL $where_and_kw";
					}
					else {
						$where_sql .= " $column_name = ? $where_and_kw";
						$arr_parameters[] = $value;
					}
				}
				elseif (!empty($value['op'])) {
					if ($value['op'] == 'MATCH') {
						$where_sql .= " MATCH($column_name) AGAINST(?) $where_and_kw";
					}
					else {
						$where_sql .= " $column_name {$value['op']} ? $where_and_kw";
					}
					$arr_parameters[] = $value['val'];

					if (!in_array($value['op'], self::$valid_ops)) {
						$this->log_mysql_error("Passed invalid op to query: {$value['op']}", $qry.$where_sql, $arr_parameters);
						return null;
					}
				}
				else {
					$where_sql .= " $column_name IN (". implode(',', array_fill(0, count($value), '?')).") $where_and_kw";
					$arr_parameters = array_merge($arr_parameters, $value);
				}
			}

			$where_sql = substr($where_sql, 0, -3);

			// Wrap in parens when ANDing on a raw fragment, so OR-mode conditions here don't leak into it
			$qry .= $has_raw_where ? " ($where_sql)" : $where_sql;
		}
		if ($has_raw_where) {
			$qry .= count($arr_where_parameters) ? ' AND (' : ' (';
			$qry .= $raw_where['sql'].')';
			$arr_parameters = array_merge($arr_parameters, $raw_where['params']);
		}
		if (count($group_by)) {
			$qry .= ' GROUP BY '.implode(',', $group_by);
		}
		if ($having) {
			if (strtoupper(substr($having, 0, 6)) != 'HAVING') {
				$qry .= ' HAVING';
			}
			$qry .= ' '.$having;
		}
		
		if (!empty($arr_order_by_parameters)) {
			if (!is_array($arr_order_by_parameters)) {
				$qry .= " ORDER BY ".$arr_order_by_parameters;
			}
			else {
				$qry .= " ORDER BY ".implode(', ', $arr_order_by_parameters);
			}
		}
		if ($limit) {
			if (is_array($limit)) {
				$qry .= " LIMIT ".$limit[0].", ".$limit[1];
				$intval = intval($limit[1]);
			}
			else {
				$qry .= " LIMIT $limit";
				$intval = intval($limit);
			}
		}
		
		$result = $this->run_unnamed_query($qry, $arr_parameters, $return_result);
		
		// Return single result if user specified a column and
		// there is only one row and one column
		if (!empty($return_result) && $arr_return_columns != '*'
			&& (is_string($arr_return_columns) || count($arr_return_columns) == 1)
			&& $intval == 1 && !empty($result)) {
			foreach ($result as $row) {
				foreach ($row as $value) {
					$result = $value;
				}
			}
		}
		// Return only single row if requested
		elseif (!empty($return_result) && $intval == 1 && !empty($result) && count($result) == 1) {
			$result = $result[0];
		}
		
		return $result;
	}
	
	/**
	 * Run an update query
	 *
	 * @param string $table_name Name of table
	 * @param array $arr_where_parameters Array of column conditionals (column => matched_value). Supports :NULL: for 'IS NULL', ':NOTNULL:' for 'IS NOT NULL', an array for IN()
	 * @param array $arr_update_parameters Array of columns to update (column => new_value). Supports values: 'NOW()', ':NULL:', or a real NULL value
	 * @param bool $where_and Optional. Use AND between WHERE true/false? If false, OR is used. Default true.
	 * @param bool $ignore Optional. If true, Ignore errors like duplicate-key conflicts. Default false
	 * @return bool True on success, False on failure
	 */
	public function update(
		string $table_name,
		array $arr_where_parameters = [],
		array $arr_update_parameters = [],
		bool $where_and = true,
		bool $ignore = false
	) {
		if ($this->replica) {
			throw new \PDOException('Do not call update() from a read replica');
		}
		if (empty($arr_where_parameters) || empty($arr_update_parameters)) {
			$this->error = "Update of $table_name needs where and update parameters";
			Log::error($this->error);
			return false;
		}
		
		if (strpos($table_name, '.') === false) {
			$table_name = "`$table_name`";
		}
		if ($ignore) {
			$qry = "UPDATE IGNORE $table_name SET";
		}
		else {
			$qry = "UPDATE $table_name SET";
		}
		$arr_parameters = [];
		
		foreach ($arr_update_parameters as $update_column => $update_value) {
			if ($update_value === null || $update_value === ':NULL:') {
				$qry .= " `".$update_column."` = NULL,";
			}
			// This regex matches "NOW()" or "NOW() +/- INT" Allowing you to modify the time upon insert. EG: "NOW() -5"
			// preg_match('/^NOW\(\)(( ?)[+-]( ?)[0-9]+)?$/', $string)
			elseif (!is_array($update_value) && preg_match('/^NOW\(\)(( ?)[+-]( ?)[0-9]+)?$/', $update_value) === 1) {
				$qry .= " `".$update_column."` = $update_value,";
			}
			else {
				$arr_parameters[] = $update_value;
				$qry .= " `".$update_column."`=?,";
			}
		}
		
		$where_and = $where_and ? 'AND' : ' OR'; // Don't remove the space before OR. It's needed because of our bad code.
		// Removes trailing comma
		$qry = substr($qry, 0, -1)." WHERE";
		foreach ($arr_where_parameters as $where_column => $where_value) {
			if (!is_array($where_value)) {
				if ($where_value === ':NULL:') {
					$qry .= " $where_column IS NULL $where_and";
				}
				elseif ($where_value === ':NOTNULL:') {
					$qry .= " $where_column IS NOT NULL $where_and";
				}
				else {
					$qry .= ' '.$where_column.'=? '.$where_and;
					$arr_parameters[] = $where_value;
				}
			}
			else {
				$qry .= " $where_column IN (". implode(',', array_fill(0, count($where_value), '?')).") $where_and";
				$arr_parameters = array_merge($arr_parameters, $where_value);
			}
		}
		$qry = substr($qry, 0, -3);
		
		$result = $this->run_unnamed_query($qry, $arr_parameters, false);
		Globals::instance()->last_db_write_time = microtime(true);
		return $result;
	}
	
	/**
	 * Run a stored procedure. Does not support output parameters.
	 * @param string $name				Name of stored procedure
	 * @param array $input				Array of values to pass in
	 * @param boolean $return_result			Optional. Return results on completion? If false, a success boolean is returned. Default false.
	 * @param boolean $debug					Optional. If true, show debugging output. Default false
	 * @return mixed
	 */
	public function call_stored_procedure($name, $input = null, $return_result = false, $debug = false)
	{
		$qry = "CALL $name";
		if (count($input)) {
			$val_placeholder = implode(',', array_fill(0, count($input), '?'));
			$qry .= "($val_placeholder)";
		}
		if ($debug) {
			$this->log_next();
		}
		return $this->run_unnamed_query($qry, $input, $return_result);
	}
	
	/**
	 * Get last ID inserted into DB. Returns string 0 if nothing inserted/updated
	 * DOES NOT SUPPORT $fallback_to_primary_if_empty
	 * @return mixed String of id on success, -1 if no results found
	 */
	public function get_last_insert_id()
	{
		// Fallback to primary if last query was run on primary
		if ($this->replica && Globals::instance()->last_query_db_type == 'primary') {
			return DB::instance(false)->get_last_insert_id();
		}
		
		return ($this->result) ? $this->connection->lastInsertId() : -1;
	}
	
	/**
	 * Get array of possible ENUM values to the given table and column
	 * @param string $table Table column resides in
	 * @param string $column Column name
	 * @return array Array of enum values
	 */
	public function get_enum_values($table, $column)
	{
		$result = $this->select(
			'INFORMATION_SCHEMA.COLUMNS',
			['TABLE_SCHEMA' => Config::get('db')['name'], 'TABLE_NAME' => $table, 'COLUMN_NAME' => $column],
			'COLUMN_TYPE',
			limit: 1,
			return_result: true
		);
		return explode("','", substr($result, 6, -2));
	}
	
	public function get_error()
	{
		// Fallback to primary if last query was run on primary
		if ($this->replica && Globals::instance()->last_query_db_type == 'primary') {
			return DB::instance(false)->get_error();
		}
		
		return $this->error;
	}
	
	/**
	 * Returns the number of rows affected by the last SQL statement
	 * DOES NOT SUPPORT $fallback_to_primary_if_empty
	 * @return int
	 */
	public function get_num_rows()
	{
		// Fallback to primary if last query was run on primary
		if ($this->replica && Globals::instance()->last_query_db_type == 'primary') {
			return DB::instance(false)->get_num_rows();
		}
		
		return ($this->result) ? $this->result->rowCount() : 0;
	}
	
	/**
	 * Get the next row to our result: fetch_both is both indexes
	 * DOES NOT SUPPORT $fallback_to_primary_if_empty
	 * @param bool $associative	true for resulting columns as associative array,
	 * false for numeric. Default true.
	 * @return mixed
	 */
	public function get_next_row(bool $associative = true): mixed
	{
		// Fallback to primary if last query was run on primary
		if ($this->replica && Globals::instance()->last_query_db_type == 'primary') {
			return DB::instance(false)->get_next_row();
		}
		
		$fetchAs = $associative ? PDO::FETCH_ASSOC : PDO::FETCH_NUM;
		return !empty($this->result) ? $this->result->fetch($fetchAs) : null;
	}
	
	/**
	 * Get all rows to our result
	 * @param bool $associative	true for resulting columns as associative array, false for numeric. Default true.
	 * @return mixed
	 */
	public function get_all_rows(bool $associative = true)
	{
		// Fallback to primary if last query was run on primary
		if ($this->replica && Globals::instance()->last_query_db_type == 'primary') {
			return DB::instance(false)->get_all_rows();
		}
		
		$fetchAs = $associative ? PDO::FETCH_ASSOC : PDO::FETCH_NUM;
		$result = !empty($this->result) ? $this->result->fetchAll($fetchAs) : null;
		if (empty($result) && $this->fallback_to_primary_if_empty && $this->replica) {
			$this->fallback_to_primary_if_empty = false; // Must be reset here to prevent infinite loop
			$result = call_user_func_array([DB::instance(), $this->last_query['function_name']], $this->last_query['params']);
		}
		
		$this->last_query = [];
		$this->fallback_to_primary_if_empty = false;
		return $result;
	}
	
	/**
	 * Is this a connection to a replica?
	 * @return bool
	 */
	public function is_replica(): bool
	{
		return $this->replica;
	}
	
	/**
	 * Log the next query
	 * @return void
	 */
	public function log_next(): void
	{
		$this->log_next_query = true;
	}
	
	/**
	 * Flatten nested arrays into JSON string
	 * This approach ensures that we're getting an array with only scalar values for the database execution method
	 * @param mixed[] $array with nested arrays
	 * @return mixed[]
	 */
	private function flattenArray(array $array): array
	{
		if (empty($array)) {
			return $array;
		}
		
		foreach ($array as $key => &$value) {
			if (is_array($value)) {
				Log::error("FIXME: Nested array being passed into DB query. If needed a json_encoded values should be passed instead. Current value for array key {$key}: ", $value);
				$value = json_encode($value);
			}
		}
		
		return $array;
	}
	
	/**
	 * Check if a failed query can be run again after a deadlock.
	 * MySQL rolls back the full transaction on a deadlock, so a query in a transaction must not run again.
	 * @param \Exception $e Exception from the failed query
	 * @return bool TRUE if the error is a deadlock outside of a transaction
	 */
	private function can_retry_deadlock(\Exception $e): bool
	{
		if (stripos($e->getMessage(), 'Deadlock found') === false) {
			return false;
		}
		if ($this->in_transaction) {
			Log::warning('DB: Deadlock rolled back the active transaction. The query will not run again.');
			return false;
		}
		return true;
	}

	/**
	 * Write MYSQL error to custom log with error messsage, query, parameters shown
	 * @param string $error
	 * @param string $query
	 * @param array $arr_parameters
	 * @return void
	 */
	private function log_mysql_error(string $error, string $query, array $arr_parameters = []): void
	{
		if ($this->replica) {
			$error = "(Replica) ".$error;
		}
		if (
			stripos($error, 'Deadlock found') !== false
			||
			stripos($error, 'Lock wait timeout') !== false
		) {
			// Log less detail on deadlocks for now since it was happening a lot
			Log::warning("MySQL Error: $error\nQuery: $query");
		}
		else {
			Log::error("MySQL Error: $error\nQuery: $query", $arr_parameters);
			if (str_contains($error, 'MySQL server has gone away')) {
				$this->connected = false;
			}
		}
	}
	
	/**
	 * Convert query and params into a failure string for logging
	 * @param string $query_with_placeholders
	 * @param mixed[] $arr_parameters
	 * @return string
	 */
	public static function get_fail_message($query_with_placeholders, $arr_parameters): string
	{
		return "'$query_with_placeholders' with parameters (".implode(
			',',
			$arr_parameters
		).") failed";
	}
	
	/**
	 * Set global flag indicating if last query was run on primary or replica DB
	 * @return void
	 */
	private function set_query_db_type()
	{
		Globals::instance()->last_query_db_type = empty($this->replica) ? 'primary' : 'replica';
	}
	
	/** for each unnamed parameter needed, return question mark and comma as needed
	 * ex. for get_unnamed_parameters(5), return ?,?,?,?,?
	 * @param int $qty Number of parameters
	 * @return string String full of appropriate number of Question marks
	 */
	public static function get_unnamed_parameters(int $qty): string
	{
		return implode(',', array_fill(0, $qty, '?'));
	}
	
	/**
	 * Ex. for get_unnamed_where_parameters(4), return ? = ? AND ? = ? AND ? = ? AND ? = ?
	 * @param int $qty Number of parameter pairs
	 * @return string String full of appropriate number of Question marks
	 */
	public static function get_unnamed_where_parameters(int $qty): string
	{
		return implode(' AND ', array_fill(0, $qty, '? = ?'));
	}
	
	// Prevent cloning
	private function __clone()
	{
	}

	// Prevent unserialization
	public function __wakeup()
	{
		throw new \Exception("Cannot unserialize a singleton.");
	}
}
