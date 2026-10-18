<?php

namespace PSP\Tests\Process;

use PHPUnit\Framework\TestCase;
use PSP\App\Config;
use PSP\Process\Scheduler;

use function Patchwork\redefine;

class SchedulerConfigTest extends TestCase
{
	protected function tearDown(): void
	{
		\Patchwork\restoreAll();
	}

	public function test_job_launcher_returns_code_5_without_a_job_limit(): void
	{
		redefine(Config::class . '::get', fn (string $config_type) => []);

		$result = Scheduler::RunJobLauncher();

		$this->assertFalse($result['success']);
		$this->assertSame(5, $result['code']);
	}
}
