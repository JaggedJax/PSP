<?php

namespace PSP\Tests\Process;

use Amp\DeferredCancellation;
use Amp\Process\Internal\Posix\PosixRunner;
use Amp\Process\Process;
use PSP\Db\DB;
use PSP\Process\Scheduler;
use Patchwork\CodeManipulation\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

use function Amp\async;
use function Patchwork\redefine;
use function Patchwork\relay;

class SchedulerTest extends TestCase
{
	private static DB $db;
	private static int|string $testJobId;
	private static int|string $testScheduleId;

	private int $original_candidate_multiplier;
	/** @var array<int, \Patchwork\CallRerouting\Handle> Redefinitions made by this test */
	private array $handles = [];
	private int $original_output_max_bytes;

	public static function setUpBeforeClass(): void
	{
		self::$db = DB::instance();

		// Inactive schedule and disabled job, so that RunQueueJobs does not queue the test job
		self::$db->insert('job_schedules', [
			'name' => 'Scheduler Unit Test Schedule',
			'minute' => '*',
			'hour' => '*',
			'day_of_month' => '*',
			'month' => '*',
			'day_of_week' => '*',
			'active' => 'N'
		]);
		self::$testScheduleId = self::$db->get_last_insert_id();

		self::$db->insert('jobs', [
			'job_name' => 'Scheduler Unit Test Job',
			'path' => __DIR__,
			'command' => 'php dummy_job.php --exit-code=0',
			'job_schedule_id' => self::$testScheduleId,
			'enabled' => 'N',
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

	protected function setUp(): void
	{
		$this->original_candidate_multiplier = Scheduler::$claim_candidate_multiplier;
		$this->original_output_max_bytes = Scheduler::$output_max_bytes;
	}

	protected function tearDown(): void
	{
		// Restore only our own redefinitions. restoreAll() also removes the stubs from phpunit.bootstrap.php
		foreach ($this->handles as $handle) {
			\Patchwork\restore($handle);
		}
		$this->handles = [];
		Stream::wrap();
		Scheduler::$claim_candidate_multiplier = $this->original_candidate_multiplier;
		Scheduler::$output_max_bytes = $this->original_output_max_bytes;
		self::$db->delete('job_log', ['job_id' => self::$testJobId]);
	}

	/**
	 * Stop the Patchwork stream wrapper, so that proc_open() can open /dev/null for child processes.
	 * Classes loaded after this call cannot be redefined. tearDown() starts the wrapper again
	 * @return void
	 */
	private function allow_processes(): void
	{
		// Loading a class file through Patchwork turns the stream wrapper back on, and launchJob() loads Amp classes
		// lazily. So unwrap at the last moment, just before PosixRunner calls proc_open(). The class must be loaded first,
		// while the wrapper is still on, for Patchwork to be able to redefine it
		class_exists(PosixRunner::class);
		$this->stub(PosixRunner::class . '::start', function () {
			Stream::unwrap();
			return relay(func_get_args());
		});
		Stream::unwrap();
	}

	/**
	 * Redefine a function or method until the end of the test
	 * @param string $target Function or method name
	 * @param callable $callback Replacement
	 * @return void
	 */
	private function stub(string $target, callable $callback): void
	{
		$this->handles[] = redefine($target, $callback);
	}

	/**
	 * Wait on the event loop. Amp\delay() is a stub in phpunit.bootstrap.php
	 * @param float $seconds Time to wait
	 * @return void
	 */
	private static function wait(float $seconds): void
	{
		$suspension = EventLoop::getSuspension();
		EventLoop::delay($seconds, fn () => $suspension->resume());
		$suspension->suspend();
	}

	/**
	 * Call a private static Scheduler method
	 * @param string $method Method name
	 * @param mixed ...$args Method arguments
	 * @return mixed Method result
	 */
	private static function call_private(string $method, mixed ...$args): mixed
	{
		return (new \ReflectionMethod(Scheduler::class, $method))->invoke(null, ...$args);
	}

	public function testDrainStreamKeepsFirstBytesAndMarksTruncation(): void
	{
		$this->allow_processes();
		Scheduler::$output_max_bytes = 1000;
		$process = Process::start('php -r ' . escapeshellarg('echo str_repeat("x", 200000);'));

		// Keep a reference. DeferredCancellation cancels when it is destroyed
		$cancellation = new DeferredCancellation();
		$output = self::call_private('drain_stream', $process->getStdout(), $cancellation->getCancellation());

		// The process exits because its output was read past the pipe buffer size
		$this->assertSame(0, $process->join());
		$this->assertSame(str_repeat('x', 1000) . "\n[output truncated]", $output);
	}

	public function testDrainStreamReturnsOutputReadBeforeCancel(): void
	{
		$this->allow_processes();
		// The background sleep keeps stdout open after the shell exits
		$process = Process::start('sh -c ' . escapeshellarg('echo hi; sleep 3 &'));
		$cancellation = new DeferredCancellation();
		$future = async(fn () => self::call_private('drain_stream', $process->getStdout(), $cancellation->getCancellation()));

		$this->assertSame(0, $process->join());
		self::wait(0.2);
		$this->assertFalse($future->isComplete(), 'Stream must stay open while the background process runs');

		$cancellation->cancel();
		$this->assertSame("hi\n", $future->await());
	}

	public function testClaimQueuedRowsSkipsRowsClaimedByOtherServers(): void
	{
		// Keep all rows in one uncommitted transaction, so that live job launchers on this DB cannot claim them
		self::$db->beginTransaction();
		$this->stub(DB::class . '::beginTransaction', fn () => true);
		$this->stub(DB::class . '::commit', fn () => true);
		try {
			$this->run_claim_test();
		}
		finally {
			self::$db->rollBack();
		}
	}

	/**
	 * Claim test body. Runs inside the transaction of testClaimQueuedRowsSkipsRowsClaimedByOtherServers()
	 * @return void
	 */
	private function run_claim_test(): void
	{
		$log_ids = [];
		for ($i = 0; $i < 25; $i++) {
			self::$db->insert('job_log', ['job_id' => self::$testJobId, 'status' => 'Q']);
			$log_ids[] = (int)self::$db->get_last_insert_id();
		}

		// Small candidate pool, so that the claim needs more than one batch
		Scheduler::$claim_candidate_multiplier = 2;

		// Another server claims the first 5 candidates after the unlocked read, before the locked check
		$raced_ids = [];
		$this->stub(DB::class . '::run_static_query', function ($query, $return_result = false) use (&$raced_ids) {
			$result = relay(func_get_args());
			if (empty($raced_ids) && is_array($result) && str_starts_with($query, 'SELECT log_id FROM job_log')) {
				$raced_ids = array_map('intval', array_slice(array_column($result, 'log_id'), 0, 5));
				DB::instance()->run_unnamed_query(
					"UPDATE job_log SET record_locked=NOW(), hostname='other_server' WHERE log_id IN (?,?,?,?,?)",
					$raced_ids
				);
			}
			return $result;
		});

		$extra_where = ' AND job_id = ' . (int)self::$testJobId;
		$first = self::call_private('claim_queued_rows', 'job_log', 'log_id', 10, 'test_server', $extra_where);
		$second = self::call_private('claim_queued_rows', 'job_log', 'log_id', 10, 'test_server', $extra_where);
		$third = self::call_private('claim_queued_rows', 'job_log', 'log_id', 10, 'test_server', $extra_where);

		$this->assertCount(5, $raced_ids);
		$this->assertCount(10, $first);
		$this->assertCount(10, $second);
		$this->assertSame([], $third, 'All unclaimed rows were already claimed');

		$claimed_ids = array_map('intval', array_merge(array_column($first, 'log_id'), array_column($second, 'log_id')));
		$this->assertCount(20, array_unique($claimed_ids), 'No row is claimed twice');
		$this->assertSame([], array_intersect($claimed_ids, $raced_ids), 'Rows claimed by another server are skipped');

		$first_ids = array_map('intval', array_column($first, 'log_id'));
		$sorted_ids = $first_ids;
		sort($sorted_ids);
		$this->assertSame($sorted_ids, $first_ids, 'Claimed rows are returned oldest first');

		$rows = self::$db->select('job_log', ['log_id' => $claimed_ids], ['hostname', 'record_locked']);
		foreach ($rows as $row) {
			$this->assertSame('test_server', $row['hostname']);
			$this->assertNotEmpty($row['record_locked']);
		}
	}

	/**
	 * Exit code, expected job list status, and if the run_on_success job is queued
	 * @return array<string, array{int, string, bool}>
	 */
	public static function exitCodeProvider(): array
	{
		return [
			'success queues next job' => [0, 'success', true],
			'failure does not queue next job' => [3, 'failed', false],
			'skipped does not queue next job' => [Scheduler::EXIT_STATUS_SKIPPED, 'failed', false],
		];
	}

	#[DataProvider('exitCodeProvider')]
	public function testLaunchJobRecordsExitCode(int $exit_code, string $expected_status, bool $expect_next_job): void
	{
		$completed_code = null;
		$queued_next = null;
		$this->stub(Scheduler::class . '::mark_job_log_running', fn () => null);
		$this->stub(Scheduler::class . '::mark_job_log_complete', function ($log_id, $code) use (&$completed_code) {
			$completed_code = $code;
		});
		$this->stub(Scheduler::class . '::queueJob', function ($job_id) use (&$queued_next) {
			$queued_next = $job_id;
			return true;
		});

		$job = [
			'job_id' => self::$testJobId,
			'log_id' => 'test_launch',
			'path' => __DIR__,
			'command' => "php dummy_job.php --exit-code=$exit_code",
			'launch_attempts' => 0,
			'run_on_success' => 999999,
		];
		$job_list = [];
		$this->allow_processes();
		Scheduler::launchJob($job, $job_list);

		for ($i = 0; $i < 100 && in_array($job_list['test_launch']['status'], ['launching', 'started'], true); $i++) {
			self::wait(0.1);
		}

		$this->assertSame($expected_status, $job_list['test_launch']['status']);
		$this->assertSame($exit_code, $job_list['test_launch']['exit_code']);
		$this->assertNotEmpty($job_list['test_launch']['pid']);
		$this->assertSame($exit_code, $completed_code);
		$this->assertSame($expect_next_job ? 999999 : null, $queued_next);
	}
}
