<?php

namespace PSP\Tests\Extensions;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

use function Patchwork\always;
use function Patchwork\redefine;

/**
 * Ensures global Patchwork stubs (such as Amp\delay, sleep, usleep, and log writes) are restored
 * before and after every test, preventing tests that call Patchwork\restoreAll() in tearDown()
 * from causing subsequent tests to wait on real sleeps or delays.
 */
final class GlobalMocksExtension implements Extension
{
	public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
	{
		$facade->registerSubscribers(
			new class () implements PreparedSubscriber {
				public function notify(Prepared $event): void
				{
					GlobalMocksExtension::apply();
				}
			},
			new class () implements FinishedSubscriber {
				public function notify(Finished $event): void
				{
					GlobalMocksExtension::apply();
				}
			}
		);
	}

	public static function apply(): void
	{
		try {
			redefine('Amp\delay', always(null));
		}
		catch (\Throwable) {
		}
		try {
			redefine('sleep', always(0));
		}
		catch (\Throwable) {
		}
		try {
			redefine('usleep', always(null));
		}
		catch (\Throwable) {
		}
		try {
			redefine('Monolog\Logger::addRecord', always(true));
		}
		catch (\Throwable) {
		}
	}
}
