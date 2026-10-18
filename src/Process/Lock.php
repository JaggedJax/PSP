<?php

namespace PSP\Process;

use PSP\App\Server;
use PSP\Db\DB;
use PSP\Log\Log;
use PSP\Util\Crypt;

use function Amp\delay;

class Lock
{
	private string $lock_level;
	private int $lock_level_pk;
	private string $lock_name;
	private string $lock_name_hash;
	private string $server_name;
	private int|false $process_id;
	private int|null $lock_pk;
	
	public const string Global = 'Global';
	public const string Server = 'Server';
	
	/**
	 * Initialize lock object
	 * @param string $lock_name
	 * @param string $lock_level Lock::Global, Lock::Server, or an app level. EG: Customer
	 * @param int $pk PK for associated lock level (Not Needed for locks: global, server)
	 * @throws \Exception
	 */
	public function __construct(string $lock_name, string $lock_level, int $pk = 0)
	{
		$this->server_name = gethostname() ? strtolower(gethostname()) : gethostbyname(gethostname());
		$this->process_id = getmypid();
		
		if (empty($pk)) {
			$pk = 0;
		}
		if ($lock_level == 'system') {
			$lock_level = self::Global;
		}
		elseif ($lock_level == self::Server) {
			$lock_name .= '_'.$this->server_name;
		}
		
		$this->lock_level = $lock_level;
		$this->lock_level_pk = $pk;
		$this->lock_name = $lock_name;
		
		if (empty($this->lock_name) || empty($this->lock_level)) {
			throw new \Exception('lock_name and lock_level are REQUIRED for locking');
		}
		
		/* We need a version of the lock name that we can guarantee is shorter than 64 characters */
		$this->lock_name_hash = $this->hash_lock_name("{$this->lock_level}_{$this->lock_level_pk}_{$lock_name}");
		
	}
	
	/**
	 * Acquire an exclusive lock. This is atomic. NON-BLOCKING by default. BLOCKING if you pass a $blocking_timeout
	 * Remember to release the lock when done.
	 * @param int $blocking_timeout Max number of seconds to block for
	 * @return boolean Did we get the lock?
	 */
	public function get_exclusive(int $blocking_timeout = 0): bool
	{
		//TODO: Improve blocking to use a DB list to ensure scripts execute in the order locks were requested
		$this->lock_pk = null;
		
		$start_time = microtime(true);
		$elapsed_time = 0;
		
		do {
			$got_lock = false;
			// Get temp DB lock first - Used instead of a transaction to ensure this function is atomic
			if (empty($this->get_temp_db_lock())) {
				// Unable to get DB lock to start process. This probably means the DB is slow or down
				return false;
			}
			
			$lock_fields = [
				'lock_level' => $this->lock_level,
				'lock_level_pk' => $this->lock_level_pk,
				'lock_name' => $this->lock_name,
			];
			
			$existing_lock = DB::instance()->select('locks', $lock_fields, limit:1);
			
			// If there's a lock and it hasn't been released, we didn't get the lock!!
			// Release our temp lock so we can return or try again
			if (!empty($existing_lock) && empty($existing_lock['released_date'])) {
				$this->release_temp_db_lock();
			}
			else {
				$got_lock = true;
			}
			
			// Sleep between .1 and .9 seconds before we try again (only if we didn't get a lock)
			if (!empty($blocking_timeout) && !$got_lock) {
				$elapsed_time = microtime(true) - $start_time;
				// Using AMP delay to not block any other active fibers
				delay(Crypt::rand_int(1, 9) * 0.1);
			}
			
			// Loop while we are blocking, under the time limit, and the lock is held elsewhere
		}
		while (!empty($blocking_timeout) && $elapsed_time < $blocking_timeout && !$got_lock);
		
		// If there's a lock and it hasn't been released, we didn't get the lock!!
		if (!empty($existing_lock) && empty($existing_lock['released_date'])) {
			return false;
		}
		
		// Let everyone know we have the lock
		$success = false;
		
		$lock_fields = [
			'from_server' => $this->server_name,
			'process_id' => $this->process_id,
		];
		
		if (empty($existing_lock)) {
			// Create new lock entry
			$lock_fields['lock_level'] = $this->lock_level;
			$lock_fields['lock_level_pk'] = $this->lock_level_pk;
			$lock_fields['lock_name'] = $this->lock_name;
			
			try {
				$statement = DB::instance()->getConnection()->prepare(
					"INSERT HIGH_PRIORITY INTO locks "
					. "(lock_level, lock_level_pk, lock_name, acquired_date, from_server, process_id) "
					. "VALUES(:lock_level, :lock_level_pk, :lock_name, NOW(), :from_server, :process_id)"
				);
				$success = $statement->execute($lock_fields);
				if ($success) {
					$this->lock_pk = (int) DB::instance()->getConnection()->lastInsertId();
				}
				else {
					Log::error("Failed writing to locks table", DB::instance()->getConnection()->errorInfo());
				}
			}
			catch (\Exception $e) {
				// If failed to look up existing lock. Getting lock failed.
				Log::error("Failed writing to locks table: ".$e->getMessage());
			}
		}
		else {
			// Update existing lock entry
			$this->lock_pk = $existing_lock['lock_pk'];
			
			$lock_fields['acquired_date'] = 'NOW()';
			$lock_fields['released_date'] = null;
			$success = DB::instance()->update(
				'locks',
				['lock_pk' => $existing_lock['lock_pk']],
				$lock_fields
			);
		}
		
		// Release temp DB Lock
		$this->release_temp_db_lock();
		
		// Return whether insert/update locking was successful. If it was, we have the lock!
		return boolval($success);
	}
	
	/**
	 * Get lock_pk for current lock
	 * @return int|null
	 */
	public function get_lock_pk(): int|null
	{
		return $this->lock_pk;
	}
	
	/**
	 * Get lock_name for current lock
	 * @return string
	 */
	public function get_lock_name(): string
	{
		return $this->lock_name;
	}
	
	/**
	 * Release the lock. Can release a lock even if we don't have it.
	 * @param int|int[] $lock_pk Don't pass a lock_pk unless you have a good reason to, and you don't. Also accepts an array of lock pks
	 * @param bool $force_release Force release the given lock name/level even if we don't have the lock
	 * @return bool Was it released? If release fails, a cleanup process will release it eventually
	 */
	public function release_lock(int|array|null $lock_pk = null, bool $force_release = false): bool
	{
		$success = false;
		if (empty($lock_pk) && isset($this->lock_pk)) {
			$lock_pk = $this->lock_pk;
		}
		
		// Release lock by pk if known
		if (!empty($lock_pk)) {
			$fields = ['lock_pk' => $lock_pk];
			$statement = DB::instance()->getConnection()->prepare(
				"UPDATE locks SET "
				. "released_date=NOW() "
				. "WHERE lock_pk = :lock_pk"
			);
			try {
				$success = $statement->execute($fields);
			}
			catch (\Exception $e) {
				Log::error("Failed updating locks table: ".$e->getMessage());
			}
			
			if (!$success) {
				// Retry with delete
				$statement = DB::instance()->getConnection()->prepare(
					"DELETE FROM locks "
					. "WHERE lock_pk = :lock_pk"
				);
				try {
					$success = $statement->execute($fields);
				}
				catch (\Exception $e) {
					Log::error("Failed deleting from locks table: ".$e->getMessage());
				}
			}
			
		}
		// Force release lock by name
		elseif ($force_release) {
			$fields = [
				'lock_level' => $this->lock_level,
				'lock_level_pk' => $this->lock_level_pk,
				'lock_name' => $this->lock_name,
			];
			
			$statement = DB::instance()->getConnection()->prepare(
				"UPDATE locks "
				. "SET released_date=NOW() "
				. "WHERE lock_level=:lock_level "
				. "AND lock_level_pk = :lock_level_pk AND lock_name = :lock_name"
			);
			try {
				$success = $statement->execute($fields);
			}
			catch (\Exception $e) {
				Log::error("Failed updating locks table: ".$e->getMessage());
			}
			
			if (!$success) {
				// Retry with delete
				$statement = DB::instance()->getConnection()->prepare(
					"DELETE FROM locks "
					. "WHERE lock_level=:lock_level "
					. "AND lock_level_pk = :lock_level_pk AND lock_name = :lock_name"
				);
				try {
					$success = $statement->execute($fields);
				}
				catch (\Exception $e) {
					Log::error("Failed deleting from locks table: ".$e->getMessage());
				}
			}
		}
		
		if (isset($this->lock_pk) && $lock_pk == $this->lock_pk) {
			$this->lock_pk = null;
		}
		
		if (!$success) {
			//TODO: Log?
			return false;
		}
		
		return true;
	}
	
	/**
	 * Get a list of outstanding locks for the current server
	 * @param int $min_lock_pk
	 * @param int $limit
	 * @param int $older_than_min Only return locks older than X minutes
	 * @param bool $all_servers Return results for all servers, or just the current server
	 * @return array<array{lock_pk:int, process_id:int}>
	 */
	public function list_held_locks(int $min_lock_pk = 0, int $limit = 200, int $older_than_min = 0, bool $all_servers = false): array
	{
		$params = [
			'released_date' => ':NULL:',
			'lock_pk' => ['op' => '>', 'val' => $min_lock_pk],
			'acquired_date' => ['op' => '<', 'val' => date('Y-m-d H:i:s', time() - ($older_than_min * 60))],
		];
		
		if (!$all_servers) {
			$params['from_server'] = $this->server_name;
		}

		return DB::instance()->select(
			'locks',
			$params,
			['lock_pk', 'process_id'],
			'lock_pk ASC',
			$limit
		) ?: [];
	}
	
	/**
	 * Release all locks for the current server and process_id. Call this function on process shutdown to cleanup any lingering locks
	 * TODO: Store all opened locks in memory so we can skip this DB call if we know none are open for this process. Then we could enable this check in autoload.php for web requests
	 */
	public function release_all(): bool
	{
		$fields = [
			'released_date' => ':NULL:',
			'from_server'   => $this->server_name,
			'process_id'    => $this->process_id,
		];
		
		return DB::instance()->update(
			'locks',
			$fields,
			['released_date' => 'NOW()']
		);
	}
	
	/**
	 * Check if a specific process id is running on the current server. Kills stopped jobs
	 * @param int $process_id
	 * @return bool
	 */
	public static function is_process_running(int $process_id): bool
	{
		$is_running = false;
		
		// Windows
		if (str_starts_with(strtoupper(PHP_OS), 'WIN')) {
			$processes = explode("\n", shell_exec("tasklist.exe"));
			while (($process = array_pop($processes)) !== null) {
				if (empty($process)) {
					continue;
				}
				$matches = [];
				preg_match("/(.*?)\s+(\d+).*$/", $process, $matches);
				// $matches[2] will contain the pid (if available)
				if (isset($matches[2]) && $matches[2] == $process_id) {
					$is_running = true;
					break;
				}
			}
		}
		// Linux/Unix
		else {
			// First try posix function if available (*nix)
			if (function_exists('posix_kill') && function_exists('shell_exec')) {
				// Get process State Code
				$escaped_pid = escapeshellarg((string)$process_id);
				$ps_output = shell_exec("ps -p {$escaped_pid} -o stat");
				$children_are_zombies = self::are_children_zombies($process_id);
				
				// $ps_output
				// First line is the header. EG: STAT
				// Second line may have modifiers after the code, so remove them. EG: SNl
				$status = substr(
					explode("\n", $ps_output)[1] ?? '',
					0,
					1
				);
				// T - stopped by job control signal
				// t - stopped by debugger during the tracing
				// Z - defunct ("zombie") process, terminated but not reaped by its parent (cannot be killed again)
				// If process is paused/stopped, kill it.
				if (in_array($status, ['T', 't']) || $children_are_zombies) {
					Log::warning("Process {$process_id} is paused/stopped. Killing it.");
					return !(posix_kill($process_id, SIGKILL));
				}
				// If status is set but is not Z (zonmbie), job is running
				$is_running = (!empty($status) && $status !== 'Z');
			}
			elseif (function_exists('posix_getpgid')) {
				$is_running = boolval(posix_getpgid($process_id));
			} // Next check if the proc dir exists (Linux)
			elseif (is_dir('/proc')) {
				$is_running = is_dir("/proc/{$process_id}");
			}
		}
		
		return $is_running;
	}
	
	/**
	 * Check if all children for a given PID are Zombies
	 * @param int $pid
	 * @return bool True if there are children and they are all Zombies
	 */
	public static function are_children_zombies(int $pid): bool
	{
		$escaped_pid = escapeshellarg((string)$pid);
		$ps_output = shell_exec("ps --ppid {$escaped_pid} -o pid,stat");
		$all_zombies = false; // Used to ensure blank responses don't count as zombies
		// Example output format:
		//PID STAT
		//1345566 ZN
		//254245 ZN
		$child_list = explode("\n", trim($ps_output));
		if (empty($child_list) || count($child_list) == 1) {
			return false;
		}
		// First row is just the headers
		array_shift($child_list);
		foreach ($child_list as $child) {
			$child = trim($child);
			if (empty($child)) {
				// Skip over empty lines (like the last line)
				continue;
			}
			list($child_pid, $child_status) = explode(' ', $child, 2);
			// Check if first character of status is a Z
			// Additional chars indicate other things
			if ($child_status[0] !== 'Z') {
				// Return false if we find a single non-zombie child
				return false;
			}
			$all_zombies = true;
		}
		// If all children are zombies, return true
		return $all_zombies;
	}
	
	/**
	 * Get a temp DB lock to ensure other processes don't conflict while getting main lock
	 * Waits up to 10 seconds before giving up
	 * @return bool Did we get the lock?
	 */
	private function get_temp_db_lock(): bool
	{
		$statement = DB::instance()->getConnection()->prepare(
			"SELECT GET_LOCK(:lock_hash,10)"
		);
		try {
			$statement->execute(['lock_hash' => $this->lock_name_hash]);
			$temp_lock = $statement->fetch();
			return @array_pop($temp_lock);
		}
		catch (\Exception $e) {
			Log::error('Failed get_temp_db_lock: ' . $e->getMessage());
			return false;
		}
	}
	
	/**
	 * Release the held temp DB lock. You always need to call this!
	 */
	private function release_temp_db_lock(): void
	{
		$statement = DB::instance()->getConnection()->prepare(
			"SELECT RELEASE_LOCK(:lock_hash)"
		);
		try {
			$statement->execute(['lock_hash' => $this->lock_name_hash]);
		}
		catch (\Exception $e) {
			Log::error('Failed get_temp_db_lock: ' . $e->getMessage());
		}
	}
	
	/**
	 * Mysql lock names are limited to 64 characters. This will ensure we don't go over that limit
	 * @param string $lock_name
	 * @return string
	 */
	private function hash_lock_name($lock_name): string
	{
		return hash('sha1', $lock_name);
	}
}
