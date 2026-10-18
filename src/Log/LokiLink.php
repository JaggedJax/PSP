<?php

namespace PSP\Log;

use PSP\App\Config;
use PSP\App\Globals;

/**
 * Builds Grafana Explore links that show log lines in Loki by Log ID.
 * Config in psp.json: grafana.url, grafana.loki_datasource_uid, grafana.loki_selector (a label selector that matches the app logs)
 */
class LokiLink
{
	public const string DEFAULT_URL = 'http://localhost:3000';
	public const string DEFAULT_SELECTOR = '{service_name="php"}';
	public const string DEFAULT_DATASOURCE_UID = 'loki';

	/** Seconds to search before and after the History entry */
	private const int WINDOW_BEFORE = 3600;
	private const int WINDOW_AFTER = 86400;

	/** Seconds to search before the start and after the end of a job run */
	private const int PROCESS_MARGIN = 300;

	/**
	 * Make one Grafana link that searches for all the Log IDs at once
	 * @param string|null $log_ids Comma separated Log IDs, as saved in History
	 * @param string|null $near_date Date of the History entry. Narrows the time range. Example: 2026-10-12 14:03:00
	 * @return string|null Null when there are no Log IDs
	 */
	public static function link(?string $log_ids, ?string $near_date = null): ?string
	{
		$ids = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$log_ids)))));
		return $ids ? self::url($ids, $near_date) : null;
	}

	/**
	 * Make the Grafana link that shows the log lines of one process run. The pid and server identify the process
	 * @param int|null $pid Process ID the server gave the script
	 * @param string|null $hostname Server that ran the script
	 * @param string|null $from_date Start of the run. Example: 2026-10-12 14:03:00
	 * @param string|null $to_date End of the run. Null when the run is not finished
	 * @return string|null Null when the pid or server is not known
	 */
	public static function process_link(?int $pid, ?string $hostname, ?string $from_date = null, ?string $to_date = null): ?string
	{
		$hostname = trim((string)$hostname);
		if (empty($pid) || $hostname === '') {
			return null;
		}

		$from = $from_date !== null ? strtotime($from_date) : false;
		$to = $to_date !== null ? strtotime($to_date) : false;
		$range = $from !== false
			? ['from' => (string)(($from - self::PROCESS_MARGIN) * 1000), 'to' => $to !== false ? (string)(($to + self::PROCESS_MARGIN) * 1000) : 'now']
			: ['from' => 'now-24h', 'to' => 'now'];

		// Host names are saved in lower case. The logs keep the case of the server
		return self::explore('| pid=`' . (int)$pid . '` | hostname=~`(?i)^' . preg_quote(str_replace('`', '', $hostname)) . '$`', $range);
	}

	/**
	 * Make the Grafana link that shows the recent logs of one script, narrowed to one ID when the script runs for one account
	 * @param string|null $process_name Script name without .php. Example: sync_products
	 * @param array<string, int|null> $ids Log ID fields to match. Empty values are ignored. Example: ['customer_id' => 12]
	 * @param string $since Grafana start of the time range
	 * @return string|null Null when there is no process name
	 */
	public static function job_link(?string $process_name, array $ids = [], string $since = 'now-24h'): ?string
	{
		$process_name = trim((string)$process_name);
		if ($process_name === '') {
			return null;
		}

		$filter = '| process_name=`' . str_replace('`', '', $process_name) . '`';
		foreach ($ids as $field => $value) {
			if (!empty($value) && preg_match('/^[a-z_]+$/', (string)$field)) {
				$filter .= ' | ' . $field . '=`' . (int)$value . '`';
			}
		}

		return self::explore($filter, ['from' => $since, 'to' => 'now']);
	}

	/**
	 * Make the Grafana Explore link that searches the logs for any of the Log IDs
	 * @param list<string> $log_ids
	 * @param string|null $near_date Date near the log lines. Null searches the last 30 days
	 * @return string
	 */
	public static function url(array $log_ids, ?string $near_date = null): string
	{
		$time = $near_date !== null ? strtotime($near_date) : false;
		$range = $time !== false
			? ['from' => (string)(($time - self::WINDOW_BEFORE) * 1000), 'to' => (string)(($time + self::WINDOW_AFTER) * 1000)]
			: ['from' => 'now-30d', 'to' => 'now'];

		return self::explore('| log_id=~`' . implode('|', array_map(fn ($id) => preg_quote(str_replace('`', '', $id)), $log_ids)) . '`', $range);
	}

	/**
	 * Build the Grafana Explore link for the app logs, narrowed by a LogQL filter
	 * @param string $filter LogQL line filter. The current environment is always added. Example: | process_id=`4821-aB3dE9fG`
	 * @param array{from:string, to:string} $range Grafana time range
	 * @return string
	 */
	private static function explore(string $filter, array $range): string
	{
		$config = (Config::get('psp')['grafana'] ?? []) + [
			'url' => self::DEFAULT_URL,
			'loki_datasource_uid' => self::DEFAULT_DATASOURCE_UID,
			'loki_selector' => self::DEFAULT_SELECTOR,
		];

		$datasource = ['type' => 'loki', 'uid' => $config['loki_datasource_uid']];
		$panes = ['a' => [
			'datasource' => $config['loki_datasource_uid'],
			'queries' => [[
				'refId' => 'A',
				'expr' => $config['loki_selector'] . ' | environment=`' . str_replace('`', '', Globals::instance()->environment) . '` ' . $filter,
				'queryType' => 'range',
				'editorMode' => 'code',
				'direction' => 'backward',
				'datasource' => $datasource,
			]],
			'range' => $range,
		]];

		return rtrim($config['url'], '/') . '/explore?' . http_build_query(['schemaVersion' => 1, 'panes' => json_encode($panes, JSON_UNESCAPED_SLASHES), 'orgId' => 1], '', '&', PHP_QUERY_RFC3986);
	}
}
