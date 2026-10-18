<?php

namespace PSP\Log;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

/**
 * Formats log records into single-line structured JSON (NDJSON) optimized for Grafana and Loki.
 * Core metadata and injected fields are flattened to top-level keys for native querying.
 */
class JsonLogFormatter extends NormalizerFormatter
{
	/**
	 * @inheritDoc
	 */
	public function format(LogRecord $record): string
	{
		$normalizedExtra = $this->normalize($record->extra);
		$normalizedContext = $this->normalize($record->context);

		$entry = [
			'datetime' => $record->datetime->format('Y-m-d\TH:i:s.uP'),
			'channel'  => $record->channel,
			'level'    => $record->level->getName(),
			'message'  => $record->message,
		];

		if (is_array($normalizedExtra)) {
			foreach ($normalizedExtra as $key => $value) {
				$entry[$key] = $value;
			}
		}

		if (!empty($normalizedContext) && is_array($normalizedContext)) {
			$entry['context'] = $normalizedContext;
		}

		return $this->toJson($entry, true) . "\n";
	}

	/**
	 * @inheritDoc
	 */
	public function formatBatch(array $records): string
	{
		$message = '';
		foreach ($records as $record) {
			$message .= $this->format($record);
		}
		return $message;
	}
}
