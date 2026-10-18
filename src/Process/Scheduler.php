<?php

namespace PSP\Process;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use Amp\TimeoutCancellation;
use PSP\App\Config;
use PSP\App\Globals;
use PSP\App\Server;
use PSP\Cache\RateLimiter;
use PSP\Db\DB;
use PSP\Log\Log;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

/**
 * Object to manage launching and running scheduled jobs
 */
class Scheduler
{
	/**
	 * Exit code a job uses when it skips its run, for example when it does not get its lock
	 */
	public const int EXIT_STATUS_SKIPPED = 95;

	/**
	 * Number of times to loop in RunJobLauncher
	 * @var int
	 */
	public static $max_launcher_loops = 20000;

	/**
	 * Candidate IDs read per claim pass, as a multiple of the jobs requested. Set near the number of job servers
	 * @var int
	 */
	public static $claim_candidate_multiplier = 10;

	/**
	 * Max number of locked batch checks per table in one getJobs call
	 * @var int
	 */
	public static $claim_max_batches = 10;

	/**
	 * Max seconds to wait for a job process to start and report its PID
	 * @var int
	 */
	public static $launch_timeout_seconds = 30;

	/**
	 * Max seconds to wait for job output to close after the job exits
	 * @var int
	 */
	public static $output_wait_seconds = 5;

	/**
	 * Max bytes of stdout and of stderr to keep for the log for each job
	 * @var int
	 */
	public static $output_max_bytes = 65536;

	/**
	 * Retrieves jobs that need running and dispatch them to launchJobs on this server
	 * @param int $batch_size Grab and Launch this many jobs at a time
	 * @return array{success:bool, code:int|null, message:string}
	 * Return codes:
	 * 5: concurrent_job_limit missing from config > scheduler
	 * 95: Lock not acquired
	 * 96: Main loop failed
	 * null: Success
	 */
	public static function RunJobLauncher($batch_size = 10)
	{
		if (empty(Config::get('scheduler')['concurrent_job_limit'])) {
			Log::error('Must set concurrent_job_limit in config > scheduler');
			return ['success' => false, 'code' => 5, 'message' => 'Must set concurrent_job_limit in config > scheduler'];
		}
		
		$lock_name = 'job_runner';
		$lock = new Lock($lock_name, Lock::Server);
		
		if (!$lock->get_exclusive()) {
			if (!self::is_main_process_responding()) {
				Log::warning("JobLauncher: Main process not responding. Clearing main_process_id.");
				DB::instance()->update('job_servers', ['hostname' => Server::server_info()['hostname']], ['main_process_id' => null]);
			}
			return ['success' => false, 'code' => 95, 'message' => 'Didn\'t get lock'];
		};
		
		Log::info('JobLauncher: got the lock - Processing Jobs');
		
		self::set_job_runner_main_process();
		
		//$jobs_running prevents launching more jobs than our limit in config > scheduler->concurrent_job_limit
		// It is the number of entries in $completed_jobs that have not finished yet.
		$jobs_running = 0;
		$job_limit = intval(Config::get('scheduler')['concurrent_job_limit']);

		$pending_jobs = [];
		/* @var $completed_jobs array<array{status:string, exit_code:string, pid:string, job_id:string}>
		 * Possible statuses: launching, launch_failed, started, success, failed
		 */
		$completed_jobs = [];

		// Stop launching new jobs on SIGTERM or SIGINT, then wait below for the jobs that are running
		$stop_requested = false;
		$signal_ids = [];
		try {
			foreach ([SIGTERM, SIGINT] as $signal) {
				$signal_ids[] = EventLoop::unreference(EventLoop::onSignal($signal, function () use (&$stop_requested) {
					// A second signal stops the launcher now. Jobs that are running are killed
					if ($stop_requested) {
						Log::warning('JobLauncher: Second stop signal received. Exiting now without cleaning up jobs.');
						exit(1);
					}
					$stop_requested = true;
				}));
			}
		}
		catch (\Throwable $e) {
			Log::warning('JobLauncher: Signal handling is not available. Error: ' . $e->getMessage());
		}

		// If the main loop fails, continue below so that the running jobs are not killed when the script exits
		$main_loop_error = null;
		$loop_count = 0;
		try {
			//Process jobs and loop to get extra jobs for a maximum of 20,000 times
			for ($loop_count = 1; $loop_count < self::$max_launcher_loops; $loop_count++) {
				if ($stop_requested) {
					Log::info("JobLauncher: Stop signal received on loop $loop_count. Not launching more jobs.");
					break;
				}
				$jobs_running = self::prune_finished_jobs($completed_jobs);

				// Every 100 loops, print out message
				if ($loop_count % 100 == 0) {
					Log::info("JobLauncher: loop $loop_count - Currently $jobs_running running jobs");
				}
				else {
					Log::debug("JobLauncher: loop $loop_count - Currently $jobs_running running jobs");
				}
				// Every 50 loops, check and set process_id
				if ($loop_count % 50 == 0 && !self::set_job_runner_main_process(true)) {
					Log::info("JobLauncher: main_process_id changed on us. Someone else got the lock or wants us to Exit, so we'll exit.");
					break;
				}
			
				// Claim only as many jobs as there are free slots. Claimed jobs that wait more than 2 minutes
				// are unlocked by check_database() and another server can launch them
				$free_slots = $job_limit - $jobs_running - count($pending_jobs);
				$extra_jobs = ($free_slots > 0) ? Scheduler::getJobs(min($batch_size, $free_slots)) : [];
				if (!empty($extra_jobs)) {
					$pending_jobs = array_merge($pending_jobs, $extra_jobs);
					unset($extra_jobs);
					Log::info("JobLauncher: # Jobs pending launch: ".count($pending_jobs));
				}
				// Sleep for a little only when idle - no new jobs and nothing pending launch
				elseif (empty($pending_jobs)) {
					delay(0.5);
				}
			
				foreach ($pending_jobs as $id => $job) {
					// Recount when near the limit, so finished jobs free up their slots
					if ($jobs_running >= $job_limit) {
						$jobs_running = self::prune_finished_jobs($completed_jobs);
					}

					//This condition ensures we don't try to launch more than the scheduler->concurrent_job_limit jobs at a time.
					if ($jobs_running >= $job_limit) {
						Log::info("JobLauncher: Server job limit reached ($jobs_running of $job_limit jobs running). Waiting.");
						delay(1); // delay a second to give jobs time to return
					}
					else {
						Scheduler::launchJob($job, $completed_jobs);
						$jobs_running = self::prune_finished_jobs($completed_jobs);
						unset($pending_jobs[$id]); // unset $jobs launched to avoid having an ever-growing array
					}
				}
				// Re-key numeric array - Ensuring any remaining records here are at the front of the queue
				if (!empty($pending_jobs)) {
					$pending_jobs = array_values($pending_jobs);
				}
				// Brief delay to allow job launch fibers a chance to run
				delay(0.1);
				Log::debug("JobLauncher: Jobs pending launch", $pending_jobs);
			}
		}
		catch (\Throwable $e) {
			$main_loop_error = $e;
			Log::error('JobLauncher: Main loop failed. Waiting for running jobs before exit. Error: ' . $e->getMessage() . ' ' . $e->getFile() . '(' . $e->getLine() . ')');
		}
		
		Log::info("JobLauncher: Completed $loop_count job loops. Releasing $lock_name lock. Waiting for $jobs_running jobs to return then shutting down.", $completed_jobs);
		
		try {
			// Unlock and remaining pending jobs so someone else can launch them
			if (!empty($pending_jobs)) {
				self::unlock_queued_jobs(array_column($pending_jobs, 'log_id'));
			}
			self::clear_job_runner_main_process();
		}
		catch (\Throwable $e) {
			Log::warning('JobLauncher: Failed releasing pending jobs. Error: ' . $e->getMessage());
		}
		$lock->release_lock();
		DB::instance()->disconnect(); // No need to hold onto DB connection
		
		// WARNING: This can hang forever if a child process becomes defunct.
		// Until we know how to handle/prevent that, we should not call this here
		//\Revolt\EventLoop::run();
		//Log::info("JobLauncher: All launched jobs completed. Marking remaining jobs as done.");
		
		// TEMP: Since \Revolt\EventLoop::run() is disabled, we'll just wait a little bit to give things time to return
		delay(20);
		
		$counter = 0;
		//Finally, unset all necessary jobs that were left over
		// Since \Revolt\EventLoop::run(); is commented out, we loop here until all jobs have returned, rather than assuming they have all completed
		while (!empty($completed_jobs)) {
			$counter++;
			Log::info("JobLauncher: Async done. Waiting on " . count($completed_jobs) . " jobs to complete", $completed_jobs);
			delay(10);
			// Release lock again in case the above attempt failed
			$lock->release_lock();
			foreach ($completed_jobs as $jbl_id => $jb) {
				if (self::is_job_finished($jb['status'])) {
					Log::info("JobLauncher: Async done. Unsetting {$jb['status']} job_id {$jb['job_id']} job_once_id {$jb['job_once_id']} log_id {$jbl_id}", $jb);
					unset($completed_jobs[$jbl_id]);
				}
				else {
					// To prevent infinite loops, manually check about once a minute if outstanding jobs have stopped
					// Jobs without a PID are still launching. The launch timeout ends them
					if ($counter % 6 == 0) {
						if (!empty($jb['pid']) && !Lock::is_process_running($jb['pid'])) {
							Log::info("JobLauncher: Async done. Unsetting no longer running job_id {$jb['job_id']} job_once_id {$jb['job_once_id']} log_id {$jbl_id}", $jb);
							unset($completed_jobs[$jbl_id]);
						}
					}
				}
			}
		}
		
		foreach ($signal_ids as $signal_id) {
			EventLoop::cancel($signal_id);
		}

		if (isset($main_loop_error)) {
			return ['success' => false, 'code' => 96, 'message' => 'Main loop failed after ' . $loop_count . ' loops: ' . $main_loop_error->getMessage()];
		}
		return ['success' => true, 'code' => null, 'message' => "Successfully completed $loop_count job loops"];
	}

	/**
	 * Remove the finished jobs from the job list and return the number of jobs that are still active
	 * @param array $job_list passed by reference. Job list populated by launchJob()
	 * @return int Number of jobs that are launching or running
	 */
	private static function prune_finished_jobs(array &$job_list): int
	{
		foreach ($job_list as $jbl_id => $jb) {
			if (self::is_job_finished($jb['status'])) {
				Log::info("Unsetting status={$jb['status']} job_id {$jb['job_id']} job_once_id {$jb['job_once_id']} log_id {$jbl_id}");
				unset($job_list[$jbl_id]);
			}
		}
		return count($job_list);
	}

	/**
	 * Check if a job status from launchJob() is a final status
	 * @param string $status Job status
	 * @return bool TRUE if the job completed, failed, or failed to launch
	 */
	private static function is_job_finished(string $status): bool
	{
		return !in_array($status, ['launching', 'started'], true);
	}

	/**
	 * Finds jobs that need running and inserts them into the launching queue
	 * It also adds jobs to the jobs_runner table for job tracking purposes
	 * @param string $time current UTC time in format gmdate('Y-m-d H:i:00')
	 * @return array{success:bool, message:string|null}
	 */
	public static function RunQueueJobs($time)
	{
		//Sleep random 0-5 seconds to help separate job servers a bit.
		delay(rand(0, 5));
		
		$db_lock_name = 'job_inserter_'.$time;
		$lock = new Lock($db_lock_name, Lock::Global);
		
		//If lock not acquired, immediately exit. Another job server is processing this.
		if (!$lock->get_exclusive()) {
			return ['success' => false, 'message' => 'Lock not acquired, immediately exit. Another job server is processing this.'];
		}
		
		//Check if some other server is already processing this
		$sjr_entry = DB::instance()->select('jobs_runner', ['scheduled_time' => $time]);
		if (!empty($sjr_entry)) {
			//Release lock before exiting
			$lock->release_lock();
			return ['success' => false, 'message' => 'Other server is already processing jobs for time ' . $time];
		}
		
		self::check_database();
		
		//Grab the server name to load into DB later
		$hostname = Server::server_info()['hostname'];
		
		//Create an entry in jobs_runner for the server and for the current minute as the scheduled_time (Using $time with 0 seconds)
		$sjr_insert_resp = DB::instance()->insert(
			'jobs_runner',
			['scheduled_time' => $time, 'hostname' => $hostname, 'process_id' => getmypid()]
		);
		
		if (!$sjr_insert_resp) {
			Log::info('Something went wrong. Insert job '. $time . 'into jobs_runner failed');
			//Release lock before exiting
			$lock->release_lock();
			return ['success' => false, 'message' => 'Insert job '. $time . 'into jobs_runner failed'];
		}
		
		$start_timestamp = time();
		
		// Select jobs needing to be queued for running
		$qry = "SELECT jobs.job_id, jobs.allow_duplicates,"
			. " jobs.runtime_max_mins"
			. " FROM job_schedules js"
			. " INNER JOIN jobs USING (job_schedule_id)"
			. " WHERE (jobs.enabled='Y' AND js.active='Y')"
			. " AND (";
		
		$timezone_col_exists = false;
		$timezones = ['UTC'];
		try {
			// This will throw an exception if the timezone column does not exist, falling back safely
			$tz_results = DB::instance(true)->run_static_query("SELECT DISTINCT timezone FROM job_schedules WHERE active='Y' AND timezone IS NOT NULL", true);
			$timezone_col_exists = true;
			if (!empty($tz_results)) {
				$timezones = array_unique(array_merge($timezones, array_column($tz_results, 'timezone')));
			}
		}
		catch (\Throwable $e) {
			// Column likely doesn't exist yet, we stick to default UTC
		}

		$tz_conditions = [];
		foreach ($timezones as $tz_str) {
			try {
				$tz_obj = new \DateTimeZone($tz_str);
			}
			catch (\Exception $e) {
				$tz_obj = new \DateTimeZone('UTC');
				$tz_str = 'UTC';
			}

			// Break down the time into its components for this timezone
			$dt = new \DateTime($time, new \DateTimeZone('UTC'));
			$dt->setTimezone($tz_obj);
			$date = explode('-', $dt->format('i-G-j-n-N')); //0:minute, 1:hour, 2:day_of_month, 3:month, 4:day_of_week
			$date[0] = ltrim($date[0], '0'); // Strip leading zero off minute
			if (!$date[0]) {
				$date[0] = 0;
			}
			$date[4] = $date[4] % 7; // Mod day_of_week by 7 to get Sunday as 0.
			$dow_alt = ($date[4] == 0) ? 7 : null; // Cron allows Sunday as both 0 and 7

			$tz_check = $timezone_col_exists ? "(COALESCE(js.timezone, 'UTC') = '{$tz_str}') AND " : "";

			// Get jobs by scheduled date
			$tz_cond = " (" . $tz_check
				. " ("
				. " (js.minute='*' OR (js.bucket_type IS NULL AND " . self::cron_exact_match('js.minute', $date[0]) . ")
		OR (js.bucket_type='minute' AND left(js.minute, 2) = '*/' AND ({$date[0]} % substring_index(js.minute, '/', -1)) = (jobs.bucket-1))
		OR (js.bucket_type='hour' AND ({$date[0]} % 60) = (jobs.sub_bucket-1))
		OR (js.bucket_type IS NULL AND left(js.minute, 2) = '*/' AND ({$date[0]} % substring_index(js.minute, '/', -1)) = 0)
		OR " . self::cron_range_list_match('js.minute', $date[0]) . ")"
				. " AND (js.hour='*' OR " . self::cron_exact_match('js.hour', $date[1]) . "
		OR (js.bucket_type='hour' AND left(js.hour, 2) = '*/' AND ({$date[1]} % substring_index(js.hour, '/', -1)) = (jobs.bucket-1))
		OR (js.bucket_type IS NULL AND left(js.hour, 2) = '*/' AND ({$date[1]} % substring_index(js.hour, '/', -1)) = 0)
		OR " . self::cron_range_list_match('js.hour', $date[1]) . ")"
				. " AND (js.day_of_month='*' OR " . self::cron_exact_match('js.day_of_month', $date[2]) . "
		OR (left(js.day_of_month, 2) = '*/' AND ({$date[2]} % substring_index(js.day_of_month, '/', -1)) = 0)
		OR " . self::cron_range_list_match('js.day_of_month', $date[2]) . ")"
				. " AND (js.month='*' OR " . self::cron_exact_match('js.month', $date[3]) . "
		OR (left(js.month, 2) = '*/' and ({$date[3]} % substring_index(js.month, '/', -1)) = 0)
		OR " . self::cron_range_list_match('js.month', $date[3]) . ")"
				. " AND (js.day_of_week='*' OR " . self::cron_exact_match('js.day_of_week', $date[4], $dow_alt) . "
		OR (left(js.day_of_week, 2) = '*/' and ({$date[4]} % substring_index(js.day_of_week, '/', -1)) = 0)
		OR " . self::cron_range_list_match('js.day_of_week', $date[4], $dow_alt) . ")"
				. ")"
				. ")";
			
			$tz_conditions[] = $tz_cond;
		}
		
		$qry .= implode("\n\t\t\tOR", $tz_conditions);
		
		$qry .= "\n\t\t)"
			. " GROUP BY jobs.job_id ORDER BY runtime_max_mins ASC";
		
		unset($date);
		
		$num_jobs = 0;
		$offset = 0;
		$limit = 1000;
		$queuing_success = false;
		$queue_failures = 0;
		
		do {
			try {
				//DB::instance(true)->log_next();
				$start_time = microtime(true);
				$paged_qry = $qry . " LIMIT $limit OFFSET $offset";
				$jobs_to_queue = DB::instance(true)->run_static_query($paged_qry, true);
				$count = count($jobs_to_queue);
				$num_jobs += $count;
				Log::info('QueueJobs Query runtime: ' . (microtime(true) - $start_time) . ' seconds and returned '.$count.' results'/*, [$paged_qry]*/);
			}
			catch (\Throwable $e) {
				Log::critical("Failed selecting Jobs - " . $e->getMessage() . "\nQuery: $qry");
				
				//Release lock before existing
				$lock->release_lock();
				return ['success' => false, 'message' => "Failed selecting Jobs - " . $e->getMessage() . "\nQuery: $qry"];
			}
			
			if (empty($jobs_to_queue)) {
				break;
			}
			
			// Lookup which jobs are already running
			$start_times = self::job_running_queued_check(array_column($jobs_to_queue, 'job_id'));
			
			Log::info(count($start_times)." already running or queued jobs from this batch");
			Log::debug("Existing Start Times", $start_times);
			
			//Insert jobs into Queue using Scheduler::queueJob()
			//From returned jobs pass the id and check if the job is recurring or a one time job to set proper flag
			foreach ($jobs_to_queue as $job) {
				//Every job to queue from the query above should be a recurring job. We are not querying for one time jobs
				$queued_successfully = self::queueJob($job['job_id'], true, $start_times[$job['job_id']] ?? [], $job, $start_timestamp);
				unset($start_times[$job['job_id']]);

				if ($queued_successfully) {
					$queuing_success = true;
				}
				// NULL means skipped because it's already queued/running - only FALSE is a real failure
				elseif ($queued_successfully === false) {
					$queue_failures++;
				}
			}
			
			$offset += $limit;
			
		}
		while ($count >= $limit);
		
		//Mark as finished if at least one job was queued, or if there was simply nothing to do
		// (no jobs matched this minute, or all were skipped as already queued/running)
		if ($queuing_success || $queue_failures === 0) {
			//Update jobs_runner with date_finished
			//This lets us monitor how quickly we process each minute of scheduled jobs
			DB::instance()->update(
				'jobs_runner',
				['scheduled_time' => $time],
				['date_finished' => 'NOW()']
			);
		}
		else {
			// Every queue attempt failed - delete the entry in jobs_runner
			// (that way it will get re-processed later by another process)
			Log::warning("All $queue_failures job queue attempts failed. Deleting jobs_runner record for $time");
			DB::instance()->delete('jobs_runner', ['scheduled_time' => $time]);
		}
		
		// TODO: Remove after New Scheduler released
		// This finds one-time jobs that are Waiting but not yet Queued.
		// This should not happen with the new scheduler
		$onetime_jobs = DB::instance()->select('job_once', ['status' => 'W', 'record_locked' => ':NULL:']);
		if (!empty($onetime_jobs)) {
			Log::warning("Found job_once jobs in 'W' status. Queueing them now", array_column($onetime_jobs, 'job_once_id'));
			foreach ($onetime_jobs as $job_once) {
				self::queueJob($job_once['job_once_id'], false, ['status' => 'W']);
			}
			
		}
		
		
		self::find_long_running_jobs();
		
		//Release the lock
		$lock->release_lock();

		return ['success' => true, 'message' => null];
	}

	/**
	 * Build a SQL condition matching a cron schedule field exactly against the current time value.
	 * Tolerates zero-padded schedule values ('05' matches minute 5).
	 * @param string $col Schedule column to match (e.g. 'js.minute')
	 * @param int $value Current time value for the field
	 * @param int|null $alt_value Optional alias that should also match (cron allows Sunday as both 0 and 7)
	 * @return string SQL condition wrapped in parentheses
	 */
	private static function cron_exact_match(string $col, int $value, int|null $alt_value = null): string
	{
		$conditions = [];
		foreach (array_unique([$value, $alt_value ?? $value]) as $v) {
			$conditions[] = "$col='{$v}'";
			// Tolerate a zero-padded value like '05'
			if ($v < 10) {
				$conditions[] = "$col='0{$v}'";
			}
		}

		return '(' . implode(' OR ', $conditions) . ')';
	}

	/**
	 * Build a SQL condition matching a cron schedule field's range ('a-b') and list ('a,b,c') formats
	 * against the current time value. Tolerates zero-padded list entries ('00,30' matches minute 0).
	 * Range bounds are compared numerically by MySQL, so zero-padded bounds already coerce correctly.
	 * @param string $col Schedule column to match (e.g. 'js.minute')
	 * @param int $value Current time value for the field
	 * @param int|null $alt_value Optional alias that should also match (cron allows Sunday as both 0 and 7)
	 * @return string SQL condition wrapped in parentheses
	 */
	private static function cron_range_list_match(string $col, int $value, int|null $alt_value = null): string
	{
		$conditions = [];
		foreach (array_unique([$value, $alt_value ?? $value]) as $v) {
			$conditions[] = "(LOCATE('-', $col) > 0 AND substring_index($col, '-', 1)<={$v} AND substring_index($col, '-', -1)>={$v})";
			$conditions[] = "(LOCATE(',', $col) > 0 AND $col REGEXP ',0?{$v},|^0?{$v},|,0?{$v}$')";
		}

		return '(' . implode("\n\t\tOR ", $conditions) . ')';
	}

	/**
	 * This function takes in a record from the jobs or job_once table (Returned from the Scheduler::getJobs() function)
	 * and launches the job in a new thread
	 * @param array $job array of columns from jobs or job_once DB tables
	 * @param array $job_list passed by reference.
	 * [] or [jobID =>
		 * ['status' => 'launching' || 'launch_failed' || 'started' || 'failed' || 'success',
		 * 	'exit_code' => null || code,
		 * 	'pid' => null || string
		 * ]
	 * ];
	 * @return false|array ['job_id' => int, 'log_id' => int, 'status' => 'started'];
	*/
	public static function launchJob($job, &$job_list)
	{
		//Detect if we got the job from jobs or job_once.
		$job['id'] = !empty($job['job_once_id']) ? $job['job_once_id'] : $job['job_id'];
		if (!isset($job['job_id'])) {
			$job['job_id'] = null;
		}
		if (!isset($job['job_once_id'])) {
			$job['job_once_id'] = null;
		}

		// Track the job immediately, so the launcher counts it before the async function runs
		$job_list[$job['log_id']] = ['status' => 'launching', 'exit_code' => null, 'pid' => null, 'job_id' => $job['job_id'], 'job_once_id' => $job['job_once_id']];

		//Start process inside async function so the main thread does not have to wait for the process to start before moving on.
		// Otherwise, if the process failed to start, the exception would be thrown in the main thread, potentially interrupting the job loop.
		async(function () use ($job, &$job_list) {
			Log::info("launchJob: Launching job {$job['id']} log_id {$job['log_id']}. Command: {$job['command']}");
			//Grab the server name to load into DB later
			$server_name = Server::server_info()['hostname'];

			// Pass through nice so we don't hog the CPU
			if (isset(Config::get('scheduler')['nice']) && is_numeric(Config::get('scheduler')['nice'])) {
				$job['command'] = "nice -n ".Config::get('scheduler')['nice']." {$job['command']}";
			}

			// If using a feature branch, pull the latest code for that branch (used in staging for feature branch testing)
			if (Globals::instance()->environment == 'dev' && !empty($job['path']) && str_contains($job['path'], '/branch/branches/')) {
				// Path format: /var/www/app/branch/branches/{BRANCH_NAME}/other_stuff
				// The first element will be blank due to the leading slash
				$path = explode('/', $job['path']);
				$path_key = array_search('branches', $path);

				if (!self::access_branch($path[$path_key + 1])) {
					Log::warning("Failed to check out Feature Branch: {$job['path']}. Job {$job['id']} log_id {$job['log_id']} not launched.");
					$job_list[$job['log_id']] = ['status' => 'launch_failed', 'exit_code' => null, 'pid' => null, 'job_id' => $job['job_id'], 'job_once_id' => $job['job_once_id']];
					self::mark_job_launch_failed($job['log_id'], $server_name, $job['job_once_id'], $job['launch_attempts']);
					return false;
				}
			}

			$success = false;
			$process = null;
			try {
				$process = Process::start($job['command'], workingDirectory: $job['path'], cancellation: new TimeoutCancellation(self::$launch_timeout_seconds));
				$success = true;
			}
			//If a job fails to launch, we still want it to remain in Q status so we can try again later. But we want
			// to keep track of the number of times it has failed.
			catch (ProcessException $e) {
				Log::error("launchJob: Failed starting process for job_id {$job['id']} log_id {$job['log_id']} on attempt {$job['launch_attempts']}"
					. '. Error: '.$e->getMessage());
				$job_list[$job['log_id']] = ['status' => 'launch_failed', 'exit_code' => null, 'pid' => null, 'job_id' => $job['job_id'], 'job_once_id' => $job['job_once_id']];
				self::mark_job_launch_failed($job['log_id'], $server_name, $job['job_once_id'], $job['launch_attempts']);
				return false;
			}
			catch (\Throwable $e) {
				Log::error("launchJob: Failed starting process for job_id {$job['id']} log_id {$job['log_id']} on attempt {$job['launch_attempts']}"
					. '. Error: '.$e->getMessage());
				$job_list[$job['log_id']] = ['status' => 'launch_failed', 'exit_code' => null, 'pid' => null, 'job_id' => $job['job_id'], 'job_once_id' => $job['job_once_id']];
				self::mark_job_launch_failed($job['log_id'], $server_name, $job['job_once_id'], $job['launch_attempts']);
				return false;
			}
			finally {
				//Finally runs always. Ensure to only log Warns if failed. We need finally
				// because $process is not available in catch
				if (!$success && !empty($process)) {
					$std = $process->getStdout()->read();
					if (!empty($std)) {
						Log::warning(
							"launchJob: job_id {$job['id']} log_id {$job['log_id']} Stdout "
							. $job['command'] . ': '. $std
						);
					}
					$std = $process->getStderr()->read();
					if (!empty($std)) {
						Log::warning(
							"launchJob: job_id {$job['id']} log_id {$job['log_id']} Stderr "
							. $job['command'] . ': '. $std
						);
					}
				}
			}

			$pid = $process->getPid();
			$job_list[$job['log_id']] = ['status' => 'started', 'exit_code' => null, 'pid' => $pid, 'job_id' => $job['job_id'], 'job_once_id' => $job['job_once_id']];

			// Read the output while the job runs. If an output pipe is full, the job blocks and does not exit
			$output_cancellation = new DeferredCancellation();
			$output_futures = [
				'Stdout' => async(fn () => self::drain_stream($process->getStdout(), $output_cancellation->getCancellation())),
				'Stderr' => async(fn () => self::drain_stream($process->getStderr(), $output_cancellation->getCancellation())),
			];

			//After launching, thread updates the job status (status='R', date_started=NOW()) and clears the record_locked field
			self::mark_job_log_running($job['log_id'], $pid, $server_name, $job['job_once_id']);
			
			Log::info("Launched job {$job['id']} log_id {$job['log_id']} pid {$pid}. Command: {$job['command']}");

			// NULL exit code means that the result of the job is unknown
			$exit_code = null;
			try {
				DB::instance()->disconnect(); // Release DB connection while waiting for job
				$exit_code = $process->join();
			}
			catch (\Throwable $e) {
				Log::warning("launchJob: Failed retrieving process result for job_id {$job['id']} log_id {$job['log_id']}"
					. '. Error: ' . $e->getMessage());
			}

			// A background process that the job started can keep the output pipes open. Wait a limited time only
			try {
				$output = await($output_futures, new TimeoutCancellation(self::$output_wait_seconds));
			}
			catch (CancelledException) {
				$output_cancellation->cancel();
				$output = await($output_futures);
			}

			$message = "launchJob: Job returned with code (" . ($exit_code ?? 'unknown') . ") job_id {$job['id']} log_id {$job['log_id']} pid {$pid}. Command: {$job['command']}";
			$output = array_filter($output, fn ($v) => $v !== '');
			// Skipped runs (for example, lock not acquired) are normal and are not logged as warnings
			if ($exit_code !== 0 && $exit_code !== self::EXIT_STATUS_SKIPPED) {
				Log::warning($message, $output);
			}
			else {
				Log::info($message, $output);
			}

			// Record an unknown result as a failure, so that the row does not stay Running
			self::mark_job_log_complete($job['log_id'], $exit_code ?? 255, $job['job_once_id']);

			// Exit codes: 0 is success. Anything else is failure
			$job_list[$job['log_id']] = ['status' => $exit_code === 0 ? 'success' : 'failed', 'exit_code' => $exit_code, 'pid' => $pid, 'job_id' => $job['job_id'], 'job_once_id' => $job['job_once_id']];

			// Launch next job if requested
			if ($exit_code === 0 && !empty($job['run_on_success'])) {
				Log::info("Job {$job['id']} was successful. Telling job ".$job['run_on_success'].' to run next.');
				Scheduler::queueJob($job['run_on_success']);
			}

		})
		->catch(function (\Throwable $e) use (&$job_list, $job) {
			Log::warning("Async crash in job_id {$job['id']} log_id {$job['log_id']}"
				. '. Error: ' . $e->getMessage());
			//Because we don't know what happened, set exit code to null
			// Write the full entry shape in case the crash happened before the entry was created
			$job_list[$job['log_id']] = [
				'status' => 'failed',
				'exit_code' => null,
				'pid' => $job_list[$job['log_id']]['pid'] ?? null,
				'job_id' => $job['job_id'],
				'job_once_id' => $job['job_once_id'],
			];
		});

		return [
			'job_id' => $job['id'],
			'log_id' => $job['log_id'],
			'status' => 'started'
		];
	}

	/**
	 * Read a job output stream until it closes or is cancelled, so the job does not block on a full pipe.
	 * Keeps only the first $output_max_bytes bytes
	 * @param ReadableStream $stream stdout or stderr of the job process
	 * @param Cancellation $cancellation Stops the read
	 * @return string Output that was read
	 */
	private static function drain_stream(ReadableStream $stream, Cancellation $cancellation): string
	{
		$output = '';
		$truncated = false;
		try {
			while (($chunk = $stream->read($cancellation)) !== null) {
				$space = self::$output_max_bytes - strlen($output);
				if (strlen($chunk) > $space) {
					$truncated = true;
				}
				if ($space > 0) {
					$output .= substr($chunk, 0, $space);
				}
			}
		}
		catch (CancelledException) {
			// The pipe is still open after the job exited. Keep the output that was read
		}
		catch (\Throwable $e) {
			Log::warning('launchJob: Failed reading job output. Error: ' . $e->getMessage());
		}

		return $truncated ? $output . "\n[output truncated]" : $output;
	}

	/**
	 * Load a Git branch. Used to checkout Feature Branches before launching jobs for those brances
	 * @param string $branch_name
	 * @return bool Success
	 */
	public static function access_branch($branch_name)
	{
		if (empty($branch_name)) {
			Log::info("Scheduler::access_branch called with no branch name");
			return false;
		}

		$parent_base_dir = rtrim(Config::base_dir(), DIRECTORY_SEPARATOR);
		$repo = Config::get('scheduler')['branch_repo'] ?? '';
		if (empty($repo)) {
			Log::info("Scheduler::access_branch called without scheduler.branch_repo config. Skipping checkout of $branch_name");
			return false;
		}

		if (str_contains($parent_base_dir, '/branch/branches')) {
			Log::info("Already in a Feature Branch. Nested Feature Branches not allowed. Skipping checkout of $branch_name");
			return false;
		}

		// Server level lock around branch checkout
		$lock = new Lock('access_branch_'.$branch_name, Lock::Server);
		if (!$lock->get_exclusive(10)) {
			Log::info("Scheduler::access_branch {$branch_name} locked. Skipping checkout.");
			return true;
		}
		
		Log::info("Checking out feature branch: $branch_name");

		$base_dir = $parent_base_dir.'/branch/branches' . DIRECTORY_SEPARATOR . $branch_name;
		
		//TODO:
		//$new_url = Config::get()->base_url.'/branch/branches/'. urlencode($branch_name);
		//$config_file = json_decode(file_get_contents($parent_base_dir . '/config/*.json'), true);

		if (!is_dir($base_dir)) {
			// Checkout branch from Git
			mkdir($base_dir, 0777, true);
			
			// If this command breaks, check SELinux
			$git_result = self::ushell_exec('whoami; cd ' . escapeshellarg($base_dir)
				. '; git clone -c core.sshCommand="ssh -vvv -o \\"StrictHostKeyChecking no\\"" -b '
				. escapeshellarg($branch_name) . ' --depth 1 --single-branch ' . escapeshellarg($repo) . ' . 2>&1');
			// Other Option: git init;  git fetch url-to-repo branchname:refs/remotes/origin/branchname

			if (!is_dir($base_dir . '/.git')) {
				@rmdir($base_dir);
				Log::warning("Error: Could not clone branch {$branch_name} from Git", [$git_result]);
				$lock->release_lock();
				return false;
			}

			mkdir($base_dir . '/vendor');

			// Copy config
			self::ushell_exec('cp -r ' . escapeshellarg("$parent_base_dir/config/.") . ' ' . escapeshellarg("$base_dir/config/"));
			// Copy Vendor files
			self::ushell_exec('cp -r ' . escapeshellarg("$parent_base_dir/vendor/.") . ' ' . escapeshellarg("$base_dir/vendor/"));
			

			// Make sure we have the correct composer requirements
			$composer_result = self::ushell_exec('cd ' . escapeshellarg($base_dir) . '; composer install');
			Log::info("Checking out feature branch: $branch_name result", [$composer_result]);

			// Set Permissions
			self::ushell_exec('cd ' . escapeshellarg($base_dir) . '; chmod -R 666 *');
			self::ushell_exec('cd ' . escapeshellarg($base_dir) . '; find . -type d -exec chmod 777 {} \;');
		}
		
		Log::info("Updating feature branch: $branch_name");
		// Make sure branch is up to date:
		$git_result = self::ushell_exec('cd ' . escapeshellarg($base_dir) . '; git fetch origin ' . escapeshellarg($branch_name) . '; git clean -fd; git reset --hard origin/' . escapeshellarg($branch_name));
		Log::info("Updating feature branch: $branch_name result: ".$git_result);

		$lock->release_lock();
		return true;
	}

	/** Run shell commands in either Unix or Windows environments
	* @param string $cmd
	* @return string|null The output from the executed command or NULL if an error occurred or the command produces no output.
	*/
	public static function ushell_exec($cmd)
	{
		if (strpos(php_uname('s'), 'Windows') !== false) {
			return shell_exec('powershell ' . escapeshellarg($cmd));
		}
		else {
			return shell_exec($cmd);
		}
	}

	/**
	 * Queues a job in the job_log table
	 * @param int $job_id ID of the job to queue
	 * @param bool $recurring Whether this is a recurring job (true) or a one-time job (false)
	 * @param array{date_started?:string, status?:string}|null $running_status Status of the job that is currently running/queued
	 * @param array{run_now:string, allow_duplicates:string, runtime_max_mins:int}|array{} $job job record to queue used for already-running checks
	 * @param int $start_timestamp Timestamp when QueueJobs was started. Used for long-running job checks
	 * @return bool|null TRUE on success, NULL if skipped because it's already queued/running, FALSE on failure
	 */
	public static function queueJob($job_id, $recurring = true, $running_status = null, $job = [], $start_timestamp = 0)
	{
		// Perform checks to see if recurring job is already running or queued before queuing it again
		if ($recurring) {
			// Lookup job if not provided
			if (empty($job)) {
				$job = DB::instance()->select('jobs', ['job_id' => $job_id], limit:1);
			}
			// Lookup job current running status if not provided
			if (!isset($running_status)) {
				$running_status = self::job_running_queued_check([$job_id])[$job_id] ?? [];
			}
			if (empty($start_timestamp)) {
				$start_timestamp = time();
			}

			// Skip this entry - If it's already running
			if ($job['allow_duplicates'] == 'N' && !empty($running_status['date_started']) && strtotime($running_status['date_started']) + ($job['runtime_max_mins'] * 60) > $start_timestamp) {
				Log::info("Skipping job {$job_id} because it's already running");
				// run_now is left as-is in this scenario as the script may need to run again after to prevent missing anything
				return null;
			}

			// Skip this entry - If it's already queued
			// Skipped even if allow_duplicates=Y since it's already queued and hasn't started yet (Consider changing if we want to allow it in the future)
			if (!empty($running_status['queued'])) {
				Log::info("Skipping job {$job_id} because it's already queued");
				return null;
			}
			
			$data = [
				'status' => JobStatus::Queued->value,
				'job_id' => $job_id,
			];
			
			Log::debug('Adding job_id '.$job_id.' to log with queued status');
			try {
				// Insert the job into job_log
				$result = DB::instance()->insert('job_log', $data);
				return $result !== false;
			}
			catch (\Throwable $e) {
				Log::warning('Failed to queue job_id '.$job_id . '. Error: ' . $e->getMessage());
				return false;
			}
		}
		else {
			// Mark One-Time Job as queued
			if (!isset($running_status)) {
				$running_status = DB::instance()->select('job_once', ['job_once_id' => $job_id], ['status', 'job_once_id'], limit:1);
			}
			
			if (in_array($running_status['status'] ?? '', [JobStatus::Queued->value, JobStatus::Running->value])) {
				Log::info("Skipping job_once {$job_id} because it's already {$running_status['status']}");
				return null;
			}
			
			if (($running_status['status'] ?? JobStatus::Waiting->value) == JobStatus::Waiting->value) {
				DB::instance()->update('job_once', ['job_once_id' => $job_id], ['status' => JobStatus::Queued->value, 'date_queued' => 'NOW()']);
			}
		}
		return true;
	}

	/**
	 * Select the full job records from jobs or job_once DB tables. Claims and returns them
	 * @param integer $num_jobs Max number of jobs that should be returned. Defaults to 10
	 * @return array<int, array<string, mixed>>|false Return array of jobs to run. FALSE on error
	 * jobs or job_once DB tables to be launched. Also includes: log_id, launch_attempts
	*/
	public static function getJobs($num_jobs = 10)
	{
		$jobs = [];
		$server_name = Server::server_info()['hostname'];
		$limit = intval($num_jobs);

		// First get from job_once table. Max qty of half the requested $num_jobs (at least 1)
		$limit_once = max(1, intval($limit / 2));
		$queued_once_jobs = self::claim_queued_rows('job_once', 'job_once_id', $limit_once, $server_name, ' AND run_at <= NOW()');

		// Second get recurring jobs
		$queued_jobs = [];
		$limit_queued = $limit - count($queued_once_jobs ?: []);
		if ($limit_queued > 0) {
			$queued_jobs = self::claim_queued_rows('job_log', 'log_id', $limit_queued, $server_name);
		}

		// Report an error only when nothing was claimed. Claimed rows must be returned so that they are launched
		if (($queued_once_jobs === false || $queued_jobs === false) && empty($queued_once_jobs) && empty($queued_jobs)) {
			return false;
		}
		$queued_once_jobs = $queued_once_jobs ?: [];
		$queued_jobs = $queued_jobs ?: [];

		if (empty($queued_jobs) && empty($queued_once_jobs)) {
			return [];
		}

		try {
			$all_queued = array_merge($queued_jobs, $queued_once_jobs);

			$job_once_id_arr = [];
			$job_id_arr = [];
			foreach ($all_queued as $queued_row) {
				if (!empty($queued_row['job_once_id'])) {
					$job_once_id_arr[] = $queued_row['job_once_id'];
				}
				else {
					$job_id_arr[] = $queued_row['job_id'];
				}
			}

			//Implements batch select - full job records keyed by their ID
			$job_once_entries = [];
			$job_recurring_entries = [];
			// Claimed job_once rows are full records. A new row can be missing from the replica, so do not read it again
			if (!empty($job_once_id_arr)) {
				$job_once_entries = array_column($queued_once_jobs, null, 'job_once_id');
			}
			if (!empty($job_id_arr)) {
				$job_recurring_entries = array_column(
					DB::instance(true)->select('jobs', ['job_id' => array_unique($job_id_arr)]) ?: [],
					null,
					'job_id'
				);
			}

			// Build one launch entry per claimed queue row, so multiple queued rows for the
			// same job each get launched instead of overwriting each other
			foreach ($all_queued as $queued_row) {
				if (!empty($queued_row['job_once_id'])) {
					$job_entry = $job_once_entries[$queued_row['job_once_id']] ?? null;
					$log_id = 'once_' . $queued_row['job_once_id'];
				}
				else {
					$job_entry = $job_recurring_entries[$queued_row['job_id']] ?? null;
					$log_id = $queued_row['log_id'];
				}

				// Mark rows without a matching job record as failed. They cannot be launched.
				if (empty($job_entry)) {
					Log::warning("JobLauncher: Failed launching queued job log_id $log_id - Job record not found.");
					self::mark_job_launch_failed($log_id, $server_name, $queued_row['job_once_id'] ?? null, $queued_row['launch_attempts']);
					continue;
				}

				// Merge in the queue row fields: log_id and launch_attempts
				$job_entry['log_id'] = $log_id;
				$job_entry['launch_attempts'] = $queued_row['launch_attempts'];
				$jobs[] = $job_entry;
			}

		}
		catch (\Throwable $e) {
			Log::warning('JobLauncher: Failed getting jobs ready to run. Error: ' . $e->getMessage());
			return false;
		}

		return $jobs;
	}
	
	/**
	 * Claim queued rows for this server without a lock on the full queue.
	 * Reads candidate IDs without locks, then locks and claims them by primary key in small batches
	 * @param string $table job_log or job_once
	 * @param string $pk_col Primary key column of $table
	 * @param int $limit Max number of rows to claim
	 * @param string $server_name Hostname to write on the claimed rows
	 * @param string $extra_where Extra static conditions. Must start with ' AND'
	 * @return array<int, array<string, mixed>>|false Claimed queue rows ordered by primary key. FALSE on error when no rows were claimed
	 */
	private static function claim_queued_rows(string $table, string $pk_col, int $limit, string $server_name, string $extra_where = ''): array|false
	{
		if ($limit <= 0) {
			return [];
		}

		$queued_where = "status='" . JobStatus::Queued->value . "' AND record_locked IS NULL" . $extra_where;
		$pool_size = $limit * self::$claim_candidate_multiplier;
		$claimed = [];
		$batches = 0;

		while (count($claimed) < $limit && $batches < self::$claim_max_batches) {
			// Read candidate IDs without locks. Other servers can read the same IDs
			$candidates = DB::instance()->run_static_query(
				"SELECT $pk_col FROM $table WHERE $queued_where ORDER BY $pk_col ASC LIMIT $pool_size",
				true
			);
			if ($candidates === false) {
				Log::warning("JobLauncher: Failed selecting candidate $table rows");
				return $claimed ?: false;
			}
			$candidate_ids = array_column($candidates ?: [], $pk_col);
			if (empty($candidate_ids)) {
				break;
			}
			$pool_was_full = count($candidate_ids) >= $pool_size;

			// Shuffle so that servers do not all try the same rows first
			shuffle($candidate_ids);

			while (!empty($candidate_ids) && count($claimed) < $limit && $batches < self::$claim_max_batches) {
				$batches++;
				$batch_ids = array_splice($candidate_ids, 0, $limit - count($claimed));
				$rows = self::claim_batch($table, $pk_col, $batch_ids, $queued_where, $server_name);
				if ($rows === false) {
					return $claimed ?: false;
				}
				$claimed = array_merge($claimed, $rows);
			}

			// All queued rows were in the pool, so no more rows are available
			if (!$pool_was_full) {
				break;
			}
		}

		if ($batches >= self::$claim_max_batches && count($claimed) < $limit) {
			Log::info("JobLauncher: Claimed " . count($claimed) . " of $limit $table rows after $batches batches");
		}

		// Keep the oldest rows first
		usort($claimed, fn ($a, $b) => $a[$pk_col] <=> $b[$pk_col]);
		return $claimed;
	}

	/**
	 * Lock one batch of candidate rows by primary key and claim the rows that are still queued.
	 * Rows that another server holds are skipped, so servers do not wait on each other
	 * @param string $table job_log or job_once
	 * @param string $pk_col Primary key column of $table
	 * @param array<int, int|string> $ids Candidate primary key values
	 * @param string $queued_where Conditions that a row must still match to be claimed
	 * @param string $server_name Hostname to write on the claimed rows
	 * @return array<int, array<string, mixed>>|false Claimed queue rows. FALSE on error
	 */
	private static function claim_batch(string $table, string $pk_col, array $ids, string $queued_where, string $server_name): array|false
	{
		try {
			DB::instance()->beginTransaction();

			// Locks only the index records of this batch
			$rows = DB::instance()->run_unnamed_query(
				"SELECT * FROM $table WHERE $pk_col IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"
				. " AND $queued_where FOR UPDATE SKIP LOCKED",
				array_values($ids),
				true
			);
			// A failed query (for example a deadlock) ends the transaction, so stop and roll back
			if ($rows === false) {
				throw new \RuntimeException("Failed selecting $table rows to claim");
			}
			$rows = $rows ?: [];

			// Mark the rows as claimed, then the commit releases the row locks
			if (!empty($rows)) {
				$locked_ids = array_column($rows, $pk_col);
				$locked = DB::instance()->run_unnamed_query(
					"UPDATE $table SET record_locked=NOW(), hostname=?"
					. " WHERE $pk_col IN (" . implode(',', array_fill(0, count($locked_ids), '?')) . ")",
					array_merge([$server_name], $locked_ids)
				);
				if ($locked === false) {
					throw new \RuntimeException("Failed claiming $table rows");
				}
			}

			if (!DB::instance()->commit()) {
				throw new \RuntimeException("Failed to commit $table claim transaction");
			}
			return $rows;
		}
		catch (\Throwable $e) {
			Log::warning('JobLauncher: Failed claiming queued jobs. Error: ' . $e->getMessage() . ' ' . $e->getFile() . '(' . $e->getLine() . ')');
			DB::instance()->rollBack();
			return false;
		}
	}
	
	/**
	 * Get the last completed run info for a job.
	 * @param int $job_id
	 * @param int|null $exit_status 0 for only success. NULL for Any status
	 * @return null|array{log_id:int, status:string, date_stated:string, date_completed:string, exit_status:int, hostname:string}
	 */
	public static function get_last_run_record(int $job_id, int|null$exit_status = 0): ?array
	{
		$where = ['job_id' => $job_id, 'status' => JobStatus::Complete->value];
		if (isset($exit_status)) {
			$where['exit_status'] = $exit_status;
		}
		
		return DB::instance()->select('job_log', $where, '*', 'date_completed DESC', 1);
	}
	
	/**
	 * Add a new one-time job with the given parameters. Checks for and prevents duplicate entries
	 * Does not support scheduling a job on multiple dates. An update would be needed to support this.
	 * @param array $parameters job_name, path, command, run_at
	 * @param bool $rate_limit perform rate limiting?
	 * @return int|false Job Id or FALSE on failure
	 */
	public static function add_one_time_job($parameters, $rate_limit = true)
	{
		// Check for existing job first to Prevent duplicates
		$job_id = DB::instance()->select('job_once', [
			'status' => [JobStatus::Waiting->value, JobStatus::Queued->value, JobStatus::Running->value], // Waiting, Queued, Running
			'path' => $parameters['path'],
			'command' => $parameters['command'],
			// Only if job requested in the last 24 hrs
			'run_at' => ['op' => '>', 'val' => date('Y-m-d H:i:s', time() - 86400)],
		], ['job_once_id'], limit:1);
		// Return existing Job ID
		if (!empty($job_id)) {
			return $job_id;
		}
		
		if ($rate_limit) {
			$limit_name = "one_time_job_{$parameters['user_id']}";
			if (RateLimiter::allow($limit_name, 3, null, 2)) {
				return false;
			}
		}
		
		// Insert as Waiting so queueJob() below can promote it to Queued.
		// Inserting as Queued would make queueJob() skip it as "already queued" and roll back.
		$parameters['status'] = JobStatus::Waiting->value;

		DB::instance()->beginTransaction();
		if (DB::instance()->insert('job_once', $parameters)) {
			$job_once_id = DB::instance()->get_last_insert_id();
			// Queue the job immediately after insertion
			if (!self::queueJob($job_once_id, false, ['status' => $parameters['status']])) {
				DB::instance()->rollBack();
				return false;
			}
			DB::instance()->commit();
			return $job_once_id;
		}
		Log::error("Failed to insert job {$parameters['command']}");
		return false;
	}
	
	/**
	 * Indicate we got the Scheduler::jobRunner lock
	 * @param bool $validate_pid When enabled, will return FALSE if the current process ID doesn't match with the DB. Meaning someone else got the lock and we should exit
	 * @return bool Success
	 */
	public static function set_job_runner_main_process($validate_pid = false)
	{
		if ($validate_pid) {
			$main_process_id = DB::instance()->select('job_servers', ['hostname' => Server::server_info()['hostname']], ['main_process_id'], [], 1);
			if (empty($main_process_id) || $main_process_id != Globals::instance()->process_id) {
				return false;
			}
		}
		
		return DB::instance()->update('job_servers', ['hostname' => Server::server_info()['hostname']], [
			'main_process_last_contact' => 'NOW()',
			'main_process_id' => Globals::instance()->process_id,
		]);
	}
	
	/**
	 * Indicate we are no longer processing Scheduler::jobRunner
	 * @return bool
	 */
	public static function clear_job_runner_main_process()
	{
		//Only clears main_process_id if it matches our current process
		return DB::instance()->update(
			'job_servers',
			['hostname' => Server::server_info()['hostname'], 'main_process_id' => Globals::instance()->process_id],
			['main_process_id' => null]
		);
	}
	
	/**
	 * Has the main process for this server responded in the last 5 minutes
	 * @return bool
	 */
	public static function is_main_process_responding(): bool
	{
		// Returns 1 if main_process_last_contact is within last 5 minutes
		$qry = 'SELECT 1 FROM job_servers'
			. ' WHERE hostname = :hostname'
			. ' AND main_process_last_contact > DATE_SUB(NOW(), INTERVAL 5 MINUTE)';

		$result = DB::instance()->run_named_query($qry, ['hostname' => Server::server_info()['hostname']], [], true);

		return !empty($result);
	}
	
	/**
	 * Unlock job_log entries. Useful in jobRunner if some jobs were selected/locked but we won't be launching them
	 * @param array $log_ids
	 * @return bool
	 */
	public static function unlock_queued_jobs($log_ids)
	{
		if (empty($log_ids)) {
			return false;
		}
		
		$job_log_ids = [];
		$job_once_ids = [];
		
		foreach ($log_ids as $id) {
			if (is_string($id) && str_starts_with($id, 'once_')) {
				$job_once_ids[] = substr($id, 5);
			}
			else {
				$job_log_ids[] = $id;
			}
		}
		
		$success = true;
		if (!empty($job_log_ids)) {
			if (!DB::instance()->update('job_log', ['log_id' => $job_log_ids], ['record_locked' => null])) {
				$success = false;
			}
		}
		if (!empty($job_once_ids)) {
			if (!DB::instance()->update('job_once', ['job_once_id' => $job_once_ids], ['record_locked' => null])) {
				$success = false;
			}
		}
		
		return $success;
	}
	
	/**
	 * Mark a job as running
	 * @param int|string|null $log_id job_log ID, or 'once_<job_once_id>' for one-time jobs
	 * @param int $pid
	 * @param string $hostname
	 * @param int|null $job_once_id If this is a one-time job
	 * @return void
	 */
	public static function mark_job_log_running(int|string|null $log_id, int $pid, string $hostname, int|null $job_once_id = null)
	{
		if (empty($job_once_id)) {
			DB::instance()->update(
				'job_log',
				['log_id' => $log_id],
				[
					'date_started' => 'NOW()',
					'date_completed' => null,
					'record_locked' => null,
					'status' => JobStatus::Running->value,
					'pid' => $pid,
					'hostname' => $hostname
				],
			);
		}
		else {
			DB::instance()->update(
				'job_once',
				['job_once_id' => $job_once_id],
				[
					'date_started' => 'NOW()',
					'date_completed' => null,
					'record_locked' => null,
					'status' => JobStatus::Running->value,
					'pid' => $pid,
					'hostname' => $hostname
				],
			);
		}
	}
	
	/**
	 * Mark a job as Complete
	 * @param int|string|null $log_id job_log ID, or 'once_<job_once_id>' for one-time jobs
	 * @param int $exit_code
	 * @param int|null $job_once_id If this is a one-time job
	 * @return void
	 */
	public static function mark_job_log_complete(int|string|null $log_id, int $exit_code, int|null $job_once_id = null)
	{
		// exit_status column is tinyint unsigned - clamp out-of-range codes (e.g. signal deaths)
		if ($exit_code < 0 || $exit_code > 255) {
			$exit_code = 255;
		}
		
		if (empty($job_once_id)) {
			DB::instance()->update(
				'job_log',
				['log_id' => $log_id],
				[
					'date_completed' => 'NOW()',
					'record_locked' => null,
					'status' => JobStatus::Complete->value,
					'exit_status' => $exit_code
				],
			);
		}
		else {
			DB::instance()->update(
				'job_once',
				['job_once_id' => $job_once_id],
				[
					'date_completed' => 'NOW()',
					'record_locked' => null,
					'status' => JobStatus::Complete->value,
					'exit_status' => $exit_code
				],
			);
		}
	}
	
	/**
	 * Mark a job as failed to launch.<br>
	 * After 10 failures, will mark job as completed and failed. Before that will leave it queued
	 * @param int|string|null $log_id job_log ID, or 'once_<job_once_id>' for one-time jobs
	 * @param string $hostname
	 * @param int|null $job_once_id If this is a one-time job
	 * @param int $launch_attempts Number of times this job has attempted to be launched
	 * @return void
	 */
	public static function mark_job_launch_failed(int|string|null $log_id, string $hostname, int|null $job_once_id = null, int $launch_attempts = 1)
	{
		if (empty($job_once_id)) {
			// Increment job launch_attempts, unlock record
			DB::instance()->run_named_query(
				'UPDATE job_log '
				. 'SET launch_attempts=launch_attempts+1, hostname=:hostname, record_locked=NULL '
				. 'WHERE log_id = :log_id',
				['log_id' => $log_id, 'hostname' => $hostname]
			);
		}
		else {
			// Increment job launch_attempts, unlock record
			DB::instance()->run_named_query(
				'UPDATE job_once '
				. 'SET launch_attempts=launch_attempts+1, hostname=:hostname, record_locked=NULL '
				. 'WHERE job_once_id = :job_once_id',
				['job_once_id' => $job_once_id, 'hostname' => $hostname]
			);
		}
		
		// If over limit, mark job as completed and failed
		if ($launch_attempts > 10) {
			Log::warning("Job launch failed after $launch_attempts attempts. No more retries. Job log_id: $log_id job_once_id: $job_once_id");
			
			if (empty($job_once_id)) {
				DB::instance()->update(
					'job_log',
					['log_id' => $log_id],
					[
						'date_started' => 'NOW()',
						'date_completed' => 'NOW()',
						'record_locked' => null,
						'status' => JobStatus::TimedOut->value,
						'hostname' => $hostname,
						'exit_status' => 255
					],
				);
			}
			else {
				DB::instance()->update(
					'job_once',
					['job_once_id' => $job_once_id],
					[
						'date_started' => 'NOW()',
						'date_completed' => 'NOW()',
						'record_locked' => null,
						'status' => JobStatus::TimedOut->value,
						'hostname' => $hostname,
						'exit_status' => 255
					],
				);
			}
		}
	}
	
	/**
	 * Check if jobs are already queued or running
	 * @param array $job_ids
	 * @return array<int|string, array{date_started:string, status:string, queued:bool}> job_id as array key
	 * @throws \Exception
	 */
	public static function job_running_queued_check($job_ids): array
	{
		if (empty($job_ids)) {
			return [];
		}

		// Lookup which jobs are already running
		// The queued flag is separate because MAX(status) returns Running when a job has both
		// a Running and a Queued row, which would hide the queued one
		$qry = "SELECT job_id, MAX(date_started) as date_started, MAX(status) as status,"
			. " MAX(status='".JobStatus::Queued->value."') as queued FROM job_log"
			. " WHERE job_id IN (".implode(',', array_fill(0, count($job_ids), '?')).")"
			. " AND status IN ('".JobStatus::Running->value."','".JobStatus::Queued->value."') group by job_id";
		$started = DB::instance(true)->run_unnamed_query($qry, $job_ids, true);
		$start_times = [];

		if (!empty($started)) {
			while ($s = array_pop($started)) {
				$start_times[$s['job_id']] = [
					'date_started' => (string)$s['date_started'],
					'status'       => (string)$s['status'],
					'queued'       => !empty($s['queued']),
				];
			}
		}

		return $start_times;
	}
	
	/**
	 * Check that database has required Stored Proc and check for any improperly setup jobs<br>
	 * Call this before queueing new jobs within Global lock
	 * @return bool
	 */
	public static function check_database()
	{
		// Check if we have required stored procedure, and add it if it's missing
		try {
			$qry = 'SELECT ROUTINE_NAME FROM INFORMATION_SCHEMA.ROUTINES'
				. ' WHERE ROUTINE_NAME=\'job_update_schedule\''
				. ' AND ROUTINE_TYPE=\'PROCEDURE\' AND ROUTINE_SCHEMA=?';
			$procs = DB::instance()->run_unnamed_query($qry, [Config::get('db')['name']], true) ?? [];
			
			// Search for jobs missing a required buck or w/ bucket value greater than allowed
			$bad_jobs = [];
			
			$minute_bucket = "substring_index(js.minute, '/', -1)";
			$hour_bucket = "(CASE WHEN LOCATE('/', js.hour) > 0 THEN substring_index(js.hour, '/', -1) ELSE 1 END)";
			
			$qry = 'SELECT jobs.job_id, jobs.job_schedule_id FROM jobs'
				.' INNER JOIN job_schedules js USING (job_schedule_id)'
				.' WHERE (js.bucket_type = \'minute\' AND (jobs.bucket IS NULL OR jobs.bucket > '.$minute_bucket.'))'
				.' OR (js.bucket_type = \'hour\' AND (jobs.bucket IS NULL OR jobs.bucket > '.$hour_bucket.' OR jobs.sub_bucket IS NULL OR jobs.sub_bucket > 60))';
			$bad_jobs = DB::instance()->run_static_query($qry, true);
			
		}
		catch (\Exception $e) {
			Log::critical("Failed checking DB Stored Precedures - ".$e->getMessage()."\nQuery: $qry");
			return false;
		}
		
		// Add missing Stored Proc
		if (!is_array($procs) || count($procs) != 1) {
			$routine = trim(file_get_contents(dirname(__DIR__, 2) . '/db/procedures/job_update_schedule.sql'));
			try {
				$query = DB::instance()->getConnection()->prepare($routine);
				$query->execute();
				Log::notice("Added routine: job_update_schedule");
			}
			catch (\Exception $e) {
				Log::error("Failed to Add routine: job_update_schedule\n".$e->getMessage());
			}
		}
		
		// Alert on improperly setup jobs
		if (is_array($bad_jobs) && count($bad_jobs) > 0) {
			Log::warning("The following jobs are using schedules with buckets, but have missing or invalid buckets defined. We'll fix that now.", $bad_jobs);
			foreach ($bad_jobs as $bad_job) {
				try {
					DB::instance()->call_stored_procedure('job_update_schedule', [$bad_job['job_id'], $bad_job['job_schedule_id']]);
				}
				catch (\Exception $e) {
					Log::error("Couldn't update job_id {$bad_job['job_id']} to use buckets.\n".$e->getMessage());
				}
			}
			
		}
		
		// Release any locks held too long (2 Minutes) - Locks should only be held for a few seconds
		$qry = "UPDATE job_log SET record_locked=NULL WHERE status='".JobStatus::Queued->value."' and record_locked < DATE_SUB(NOW(), INTERVAL 2 MINUTE)";
		try {
			DB::instance()->run_static_query($qry);
		}
		catch (\Exception $e) {
			Log::error("Failed release jobs_log record locks\n".$e->getMessage());
		}
		$qry = "UPDATE job_once SET record_locked=NULL WHERE status='".JobStatus::Queued->value."' and record_locked < DATE_SUB(NOW(), INTERVAL 2 MINUTE)";
		try {
			DB::instance()->run_static_query($qry);
		}
		catch (\Exception $e) {
			Log::error("Failed release job_once record locks\n".$e->getMessage());
		}
		
		return true;
	}
	
	/**
	 * Find and report jobs running too long<br>
	 * Call this AFTER queueing new jobs within Global lock
	 */
	public static function find_long_running_jobs()
	{
		/*	switch ($pid = pcntl_fork()) {
				case -1: // FAILED
					Log::error('Failed forking to look for long running jobs');
					break;
				case 0: // CHILD
		 */
		$long_jobs = [];
		try {
			// Check for long-running recurring jobs
			$qry = 'SELECT log.job_id, log.log_id, s.allow_duplicates, s.runtime_max_mins'
				.' FROM job_log log'
				.' INNER JOIN jobs s USING (job_id)'
				.' WHERE log.status = \''.JobStatus::Running->value.'\' AND log.date_completed IS NULL'
				.' AND DATE_SUB(NOW(), INTERVAL s.runtime_max_mins MINUTE) > log.date_started';
			
			$recurring_long_jobs = DB::instance()->run_static_query($qry, true) ?: [];
			
			foreach ($recurring_long_jobs as &$job) {
				$job['type'] = 'recurring';
			}

			// Check for long-running one-time jobs
			$qry = 'SELECT job_once_id, runtime_max_mins, path, command'
				.' FROM job_once'
				.' WHERE status = \''.JobStatus::Running->value.'\' AND date_completed IS NULL'
				.' AND DATE_SUB(NOW(), INTERVAL runtime_max_mins MINUTE) > date_started';
			
			$once_long_jobs = DB::instance()->run_static_query($qry, true) ?: [];

			foreach ($once_long_jobs as &$job) {
				$job['type'] = 'once';
				$job['log_id'] = 'once_' . $job['job_once_id'];
				$job['allow_duplicates'] = 'Y'; // One time jobs usually allow duplicates if they are different records, but here we are checking a specific record.
			}

			$long_jobs = array_merge($recurring_long_jobs, $once_long_jobs);

		}
		catch (\Exception $e) {
			Log::error('Failed to find jobs past their runtime_max_mins: '.$e->getMessage());
		}

		$log_ids = [];
		$once_ids = [];

		foreach ($long_jobs as $long_job) {
			if ($long_job['type'] === 'recurring') {
				$log_ids[] = $long_job['log_id'];
			}
			else {
				$once_ids[] = $long_job['job_once_id'];
			}
		}

		try {
			// Update status of long_jobs to TimedOut
			if (!empty($log_ids)) {
				DB::instance()->update('job_log', ['log_id' => $log_ids], ['status' => JobStatus::TimedOut->value, 'date_completed' => 'NOW()', 'exit_status' => 255]);
			}
			if (!empty($once_ids)) {
				DB::instance()->update('job_once', ['job_once_id' => $once_ids], ['status' => JobStatus::TimedOut->value, 'date_completed' => 'NOW()', 'exit_status' => 255]);
			}
		}
		catch (\Exception $e) {
			Log::error('Failed to update jobs past their runtime_max_mins: '.$e->getMessage());
		}

		foreach ($long_jobs as $long_job) {
			Log::warning("Long-Running Script " . ($long_job['type'] == 'recurring' ? "job_id: {$long_job['job_id']} log_id: {$long_job['log_id']}" : "job_once_id: {$long_job['job_once_id']}"));
		}
	}

	/**
	 * Perform the heartbeat update for the current server
	 * @return int|false False if failed, num rows affected otherwise
	 */
	public static function update_heartbeat(): int|false
	{
		// Uses ON DUPLICATE KEY UPDATE to create or update the server record.
		return DB::instance()->insert(
			'job_servers',
			[
				'hostname' => Server::server_info()['hostname'],
				'last_heartbeat' => 'NOW()',
			],
			['last_heartbeat']
		);
	}
}
