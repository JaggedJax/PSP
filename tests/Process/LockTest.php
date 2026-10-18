<?php

namespace PSP\Tests\Process;

use PSP\Process\Lock;
use PHPUnit\Framework\TestCase;

class LockTest extends TestCase
{
	public function testIsProcessRunningWithCurrentPid(): void
	{
		$pid = getmypid();
		$this->assertTrue(Lock::is_process_running($pid));
	}

	public function testIsProcessRunningWithFakePid(): void
	{
		$fake_pid = 999999;
		$this->assertFalse(Lock::is_process_running($fake_pid));
	}

	public function testAreChildrenZombiesWithCurrentPid(): void
	{
		$pid = getmypid();
		// Usually current process has no children that are zombies
		$this->assertFalse(Lock::are_children_zombies($pid));
	}
}
