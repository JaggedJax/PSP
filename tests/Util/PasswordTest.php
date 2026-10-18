<?php

namespace PSP\Tests\Util;

use PSP\Cache\DataCache;
use PSP\Cache\DataCacheResult;
use PSP\Http\Http;
use PSP\Util\Password;
use PHPUnit\Framework\TestCase;

use function Patchwork\redefine;

class PasswordTest extends TestCase
{
	public function testValidateNewRejectsShortPassword(): void
	{
		$result = Password::validate_new('short1');
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('at least', $result['message']);
	}

	public function testValidateNewRejectsPwnedPassword(): void
	{
		$password = 'CorrectHorseBatteryStaple1!';
		$suffix = substr(strtoupper(sha1($password)), 5);

		redefine(DataCache::class . '::get', function () use ($suffix) {
			return new DataCacheResult([$suffix => 42], 24, 'json');
		});

		$result = Password::validate_new($password);
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('42', $result['message']);
	}

	public function testValidateNewAcceptsCleanPassword(): void
	{
		redefine(DataCache::class . '::get', function () {
			return new DataCacheResult([], 24, 'json');
		});

		$result = Password::validate_new('SomeReasonablySecurePassphrase42!');
		$this->assertTrue($result['valid']);
		$this->assertNull($result['message']);
	}

	public function testCheckPwnedReturnsNullWhenCacheUnavailable(): void
	{
		redefine(DataCache::class . '::get', function () {
			return null;
		});

		$this->assertNull(Password::check_pwned('anything12345'));
	}

	public function testLoginWarningIsNullWhenPasswordClean(): void
	{
		redefine(DataCache::class . '::get', function () {
			return new DataCacheResult([], 24, 'json');
		});

		$this->assertNull(Password::login_warning('CleanPassword123!'));
	}

	public function testLoginWarningWhenPasswordPwned(): void
	{
		$password = 'LeakedPassword1!';
		$suffix = substr(strtoupper(sha1($password)), 5);

		redefine(DataCache::class . '::get', function () use ($suffix) {
			return new DataCacheResult([$suffix => 5], 24, 'json');
		});

		$warning = Password::login_warning($password);
		$this->assertNotNull($warning);
		$this->assertStringContainsString('5', $warning);
	}

	public function testFetchRangeParsesResponseIntoSuffixMap(): void
	{
		redefine(Http::class . '::get', function () {
			return "0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n003D68EB55068C33ACE09247EE4C639306B:2\r\n";
		});

		$result = Password::fetch_range('5BAA6');
		$this->assertInstanceOf(DataCacheResult::class, $result);

		$data = $result->get_data();
		$this->assertSame(1, $data['0018A45C4D1DEF81644B54AB7F969B88D65']);
		$this->assertSame(2, $data['003D68EB55068C33ACE09247EE4C639306B']);
	}

	public function testFetchRangeReturnsNullOnFailure(): void
	{
		redefine(Http::class . '::get', function () {
			throw new \Exception('network down');
		});

		$this->assertNull(Password::fetch_range('5BAA6'));
	}
}
