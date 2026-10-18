<?php

namespace PSP\Tests\Log;

use PSP\Log\LokiLink;
use PHPUnit\Framework\TestCase;

class LokiLinkTest extends TestCase
{
	public function test_link_is_null_without_log_ids(): void
	{
		$this->assertNull(LokiLink::link(null));
		$this->assertNull(LokiLink::link(''));
	}

	public function test_link_searches_all_log_ids_at_once(): void
	{
		$url = LokiLink::link('261012120000-aaaaaa, 261012120001-bbbbbb', '2026-10-12 12:00:00');

		$this->assertStringStartsWith('http://localhost:3000/explore?', $url);
		parse_str(parse_url($url, PHP_URL_QUERY), $query);
		$panes = json_decode($query['panes'], true);
		$this->assertStringContainsString('| log_id=~`261012120000\-aaaaaa|261012120001\-bbbbbb`', $panes['a']['queries'][0]['expr']);
		$this->assertLessThan((int)$panes['a']['range']['to'], (int)$panes['a']['range']['from']);
	}

	public function test_process_link_is_null_without_pid_or_server(): void
	{
		$this->assertNull(LokiLink::process_link(null, 'job1'));
		$this->assertNull(LokiLink::process_link(4821, '  '));
	}

	public function test_process_link_searches_one_process_in_the_run_window(): void
	{
		$url = LokiLink::process_link(4821, 'job1', '2026-10-12 12:00:00', '2026-10-12 12:10:00');

		parse_str(parse_url($url, PHP_URL_QUERY), $query);
		$panes = json_decode($query['panes'], true);
		$this->assertStringEndsWith('| pid=`4821` | hostname=~`(?i)^job1$`', $panes['a']['queries'][0]['expr']);
		$this->assertSame(300 + 600 + 300, ((int)$panes['a']['range']['to'] - (int)$panes['a']['range']['from']) / 1000);
	}

	public function test_process_link_runs_to_now_for_an_unfinished_run(): void
	{
		$url = LokiLink::process_link(4821, 'job1', '2026-10-12 12:00:00');

		parse_str(parse_url($url, PHP_URL_QUERY), $query);
		$this->assertSame('now', json_decode($query['panes'], true)['a']['range']['to']);
	}

	public function test_job_link_is_null_without_process_name(): void
	{
		$this->assertNull(LokiLink::job_link(''));
	}

	public function test_job_link_filters_by_process_name_and_ids(): void
	{
		$url = LokiLink::job_link('sync_products', ['customer_id' => 5, 'account_id' => null, 'bad field' => 3]);

		parse_str(parse_url($url, PHP_URL_QUERY), $query);
		$panes = json_decode($query['panes'], true);
		$this->assertStringEndsWith('| process_name=`sync_products` | customer_id=`5`', $panes['a']['queries'][0]['expr']);
		$this->assertSame('now-24h', $panes['a']['range']['from']);
	}

	public function test_every_link_is_limited_to_the_current_environment(): void
	{
		$environment = \PSP\App\Globals::instance()->environment;
		$urls = [
			LokiLink::link('261012120000-aaaaaa'),
			LokiLink::process_link(4821, 'job1'),
			LokiLink::job_link('sync_products'),
		];

		foreach ($urls as $url) {
			parse_str(parse_url($url, PHP_URL_QUERY), $query);
			$this->assertStringContainsString('| environment=`' . $environment . '` |', json_decode($query['panes'], true)['a']['queries'][0]['expr']);
		}
	}
}
