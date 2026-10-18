<?php

namespace PSP\Tests\App;

use PHPUnit\Framework\TestCase;
use PSP\App\Server;

class ServerTest extends TestCase
{
	/** @var array<string, mixed> */
	private array $server_backup = [];

	protected function setUp(): void
	{
		$this->server_backup = $_SERVER;
	}

	protected function tearDown(): void
	{
		$_SERVER = $this->server_backup;
		Server::trust_ip_headers();
	}

	public function test_get_ip_ignores_proxy_headers_that_are_not_trusted(): void
	{
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

		$this->assertSame('10.0.0.1', Server::get_ip());
	}

	public function test_get_ip_uses_trusted_headers_in_order(): void
	{
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '5.6.7.8';

		Server::trust_ip_headers('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR');
		$this->assertSame('5.6.7.8', Server::get_ip());

		unset($_SERVER['HTTP_CF_CONNECTING_IP']);
		$this->assertSame('1.2.3.4', Server::get_ip());
	}

	public function test_get_ip_takes_the_first_address_of_a_forwarded_list(): void
	{
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 10.0.0.2, 10.0.0.3';

		Server::trust_ip_headers('HTTP_X_FORWARDED_FOR');
		$this->assertSame('1.2.3.4', Server::get_ip());
	}

	public function test_get_ip_is_null_without_a_web_request(): void
	{
		unset($_SERVER['REMOTE_ADDR']);

		$this->assertNull(Server::get_ip());
	}
}
