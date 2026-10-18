<?php

namespace PSP\Tests\Http;

use PSP\Http\Http;
use PHPUnit\Framework\TestCase;

class HttpTest extends TestCase
{
	/**
	 * Read the private oauth_refresh_params property - there's no public getter, and adding one
	 * solely for this test isn't worth it when reflection does the job
	 * @param Http $http
	 * @return array
	 */
	private function oauth_refresh_params(Http $http): array
	{
		$property = new \ReflectionProperty(Http::class, 'oauth_refresh_params');

		return $property->getValue($http);
	}

	public function testOauthRefreshSetupBuildsTheStartingHeaderImmediately(): void
	{
		$calls = 0;
		$header_builder = function () use (&$calls) {
			$calls++;
			return 'Authorization: Bearer initial-token';
		};

		$http = new Http('https://example.com');
		$http->oauth_refresh_setup([401], fn () => null, [], $header_builder);

		$params = $this->oauth_refresh_params($http);

		$this->assertSame(1, $calls, 'The header-building callable must be invoked once by oauth_refresh_setup() itself');
		$this->assertSame('Authorization: Bearer initial-token', $params['oauth_header']);
	}

	public function testOauthRefreshSetupStoresTheRefreshHttpStatusAsAnArray(): void
	{
		$http = new Http('https://example.com');
		$http->oauth_refresh_setup([401], fn () => null, [], fn () => 'Authorization: Bearer x');

		$params = $this->oauth_refresh_params($http);

		$this->assertSame([401], $params['refresh_http_status']);
	}

	public function testOauthRefreshSetupAcceptsMultipleRefreshHttpStatuses(): void
	{
		$http = new Http('https://example.com');
		$http->oauth_refresh_setup([401, 403], fn () => null, [], fn () => 'Authorization: Bearer x');

		$params = $this->oauth_refresh_params($http);

		$this->assertSame([401, 403], $params['refresh_http_status']);
	}
}
