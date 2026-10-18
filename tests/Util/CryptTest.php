<?php

namespace PSP\Tests\Util;

use PSP\Util\Crypt;
use PSP\Enums\YesNo;
use PHPUnit\Framework\TestCase;

class CryptTest extends TestCase
{
	public function testGuid(): void
	{
		$guid = Crypt::guid();
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $guid);

		$guid2 = Crypt::guid();
		$this->assertNotEquals($guid, $guid2);
	}

	public function testRandAlphanumeric(): void
	{
		$length = 16;
		$res = Crypt::rand_alphanumeric($length);
		$this->assertEquals($length, strlen($res));
		$this->assertMatchesRegularExpression('/^[0-9a-fA-Z]+$/i', $res);

		$res2 = Crypt::rand_alphanumeric($length, true);
		$this->assertEquals($length, strlen($res2));
		// Characters: 23456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ
		$this->assertMatchesRegularExpression('/^[2-9a-km-np-zA-HJKLMNP-Z]+$/', $res2);
	}

	public function testRandInt(): void
	{
		$min = 1;
		$max = 10;
		for ($i = 0; $i < 100; $i++) {
			$res = Crypt::rand_int($min, $max);
			$this->assertGreaterThanOrEqual($min, $res);
			$this->assertLessThanOrEqual($max, $res);
		}
	}

	public function testRandString(): void
	{
		$chars = 'ABC';
		$length = 10;
		$res = Crypt::rand_string($chars, $length);
		$this->assertEquals($length, strlen($res));
		$this->assertMatchesRegularExpression('/^[ABC]+$/', $res);
	}

	public function testRandEnum(): void
	{
		$val = Crypt::rand_enum(YesNo::class);
		$this->assertNotNull($val);

		$cases = array_map(fn ($c) => $c->value, YesNo::cases());
		$this->assertContains($val, $cases);
	}
}
