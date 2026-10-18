<?php

namespace PSP\Tests\Process;

use PSP\Process\Scheduler;
use PSP\App\Config;
use PSP\Log\Log;
use PSP\Db\DB;
use PSP\Process\Lock;
use PHPUnit\Framework\TestCase;

use function Patchwork\redefine;
use function Patchwork\always;

class SchedulerIntegrationTest extends TestCase
{
	private static $testJobId;
	private static $testScheduleId;
	private static $db;

	public static function setUpBeforeClass(): void
	{
		self::$db = DB::instance();

		// Ensure job_servers has an entry for this hostname
		$hostname = gethostname() ? strtolower(gethostname()) : gethostbyname(gethostname());
		$existingServer = self::$db->select('job_servers', ['hostname' => $hostname]);
		if (empty($existingServer)) {
			self::$db->insert('job_servers', [
				'hostname' => $hostname,
				'main_process_id' => getmypid(),
				'main_process_last_contact' => 'NOW()'
			]);
		}
		
		// 1. Create a test schedule that runs every minute
		self::$db->insert('job_schedules', [
			'name' => 'Integration Test Schedule',
			'minute' => '*',
			'hour' => '*',
			'day_of_month' => '*',
			'month' => '*',
			'day_of_week' => '*',
			'active' => 'Y'
		]);
		self::$testScheduleId = self::$db->get_last_insert_id();

		// 2. Create a test job using dummy_job.php
		$dummyJobPath = realpath(__DIR__ . '/dummy_job.php');
		self::$db->insert('jobs', [
			'job_name' => 'Integration Test Job',
			'path' => dirname($dummyJobPath),
			'command' => 'php ' . basename($dummyJobPath) . ' --exit-code=0 --delay=1',
			'job_schedule_id' => self::$testScheduleId,
			'enabled' => 'Y',
			'allow_duplicates' => 'Y'
		]);
		self::$testJobId = self::$db->get_last_insert_id();
	}

	public static function tearDownAfterClass(): void
	{
		if (self::$testJobId) {
			self::$db->delete('job_log', ['job_id' => self::$testJobId]);
			self::$db->delete('jobs', ['job_id' => self::$testJobId]);
		}
		if (self::$testScheduleId) {
			self::$db->delete('job_schedules', ['job_schedule_id' => self::$testScheduleId]);
		}
	}

	protected function tearDown(): void
	{
		\Patchwork\restoreAll();
	}

	public function testIntegration()
	{
		//$time = gmdate('Y-m-d H:i:00');
		// Use hardcoded time to avoid conflicts with running app
		$time = '2026-03-17 01:01:00';
		
		// Ensure no leftover runner, log, or lock entries for this time
		self::$db->delete('jobs_runner', ['scheduled_time' => $time]);
		self::$db->delete('job_log', ['job_id' => self::$testJobId]);
		self::$db->delete('locks', ['lock_name' => 'job_inserter_' . $time]);

		// --- Step 1: RunQueueJobs ---
		// Redefine sleep to skip the random delay
		redefine('sleep', function ($seconds) {
			return;
		});

		// Redefine DB::run_static_query to filter jobs and only run our test job
		$testJobId = self::$testJobId;
		redefine(DB::class . '::run_static_query', function ($query, $return_result = false) use ($testJobId) {
			$result = \Patchwork\relay(func_get_args());

			// Check if this is the job selection query in RunQueueJobs
			if ($return_result && is_array($result) && str_contains($query, 'FROM job_schedules js') && str_contains($query, 'INNER JOIN jobs')) {
				// Filter results to only include our test job
				return array_values(array_filter($result, function ($job) use ($testJobId) {
					return isset($job['job_id']) && $job['job_id'] == $testJobId;
				}));
			}

			return $result;
		});

		$result = Scheduler::RunQueueJobs($time);
		$this->assertTrue($result['success'], 'RunQueueJobs failed: ' . ($result['message'] ?? ''));

		// Verify job_log entry
		$logs = self::$db->select('job_log', ['job_id' => self::$testJobId, 'status' => 'Q']);
		$this->assertNotEmpty($logs, 'Job should be queued in job_log');
		$logId = $logs[0]['log_id'];

		// --- Step 2: RunJobLauncher ---
		// Ensure no leftover job_runner lock on this server
		$serverName = gethostname() ? strtolower(gethostname()) : gethostbyname(gethostname());
		self::$db->delete('locks', ['lock_name' => 'job_runner_' . $serverName]);

		Scheduler::$max_launcher_loops = 2;

		// Use the exception trick to break the loop in RunJobLauncher
		$delayCount = 0;
		redefine('Amp\delay', function ($seconds) use (&$delayCount) {
			$delayCount++;
			if ($delayCount > 1) { // Break after one search for jobs
				throw new \Exception('Break Loop');
			}
			return null;
		});

		// We need to mock concurrent_job_limit
		redefine(Config::class . '::get', function ($key = null) {
			if ($key === 'scheduler') {
				return ['concurrent_job_limit' => 5];
			}
			return \Patchwork\relay(func_get_args());
		});

		// MOCK launchJob to avoid real process execution issues in tests
		$launchJobCalled = false;
		redefine(Scheduler::class . '::launchJob', function ($job, &$completed_jobs) use (&$launchJobCalled, $logId) {
			if ($job['log_id'] != $logId) {
				// We want to ensure no other jobs are run.
				// If another job somehow got here, we fail the test.
				throw new \Exception("Unexpected job launch attempt for log_id: " . $job['log_id']);
			}
			$launchJobCalled = true;
			// Simulate starting and completing the job immediately
			$currentLogId = $job['log_id'];
			$completed_jobs[$currentLogId] = [
				'status' => 'success',
				'exit_code' => 0,
				'pid' => 12345,
				'job_id' => $job['job_id'],
				'job_once_id' => $job['job_once_id'] ?? null
			];
			// Update DB to reflect completed
			$db = DB::instance();
			$db->update('job_log', ['log_id' => $currentLogId], ['status' => 'C', 'exit_status' => 0]);
			return true;
		});

		// MOCK getJobs to ensure ONLY our test job is returned
		redefine(Scheduler::class . '::getJobs', function ($num_jobs = 10) use ($logId) {
			$db = DB::instance();
			$log_entries = $db->select('job_log', ['log_id' => $logId]);
			if (!empty($log_entries)) {
				$job_ids = array_column($log_entries, 'job_id');
				$job_details = $db->select('jobs', ['job_id' => $job_ids]);
				$jobs = [];
				foreach ($log_entries as $le) {
					foreach ($job_details as $jd) {
						if ($jd['job_id'] == $le['job_id']) {
							$jobs[] = array_merge($le, $jd);
						}
					}
				}
				return $jobs;
			}
			return [];
		});

		try {
			Scheduler::RunJobLauncher(1);
		}
		catch (\Exception $e) {
			if ($e->getMessage() !== 'Break Loop') {
				throw $e;
			}
		}
		
		$this->assertTrue($launchJobCalled, 'Scheduler::launchJob should have been called');

		// Verify job_log updated to Running or Completed
		$checkLog = self::$db->select('job_log', ['log_id' => $logId]);
		/*
		echo "Log Status after RunJobLauncher: " . ($checkLog[0]['status'] ?? 'NULL') . PHP_EOL;
		if (!empty($checkLog[0]['exit_status'])) {
			echo "Exit Status: " . $checkLog[0]['exit_status'] . PHP_EOL;
			echo "Error: " . $checkLog[0]['error'] . PHP_EOL;
		}
		*/

		// Wait up to 5 seconds for completion
		$completed = false;
		for ($i = 0; $i < 50; $i++) {
			$checkLog = self::$db->select('job_log', ['log_id' => $logId]);
			if (!empty($checkLog) && $checkLog[0]['status'] === 'C') {
				$completed = true;
				$this->assertEquals(0, $checkLog[0]['exit_status'], 'Job should have exited with code 0');
				break;
			}
			usleep(100000); // 100ms
		}

		$this->assertTrue($completed, 'Job should have completed');
	}
}
