<?php

namespace PSP\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Trace\Span;

class LogProcessor implements ProcessorInterface
{
	public function __construct()
	{

	}

	/** @var list<string> Fields moved from the log context to extra */
	private static array $fields = ['log_id', 'process_id', 'process_name', 'pid', 'hostname', 'environment', 'stacktrace'];

	/**
	 * Add app-specific fields to move from the log context to extra. EG: customer_id
	 * @param string ...$fields
	 */
	public static function add_fields(string ...$fields): void
	{
		self::$fields = array_values(array_unique(array_merge(self::$fields, $fields)));
	}

	/**
	 * Get the fields moved from the log context to extra
	 * @return list<string>
	 */
	public static function fields(): array
	{
		return self::$fields;
	}

	/**
	 * @inheritDoc
	 */
	public function __invoke(LogRecord $record): LogRecord
	{
		// Inject anything into log that we need

		// Injected Fields to move from context to extra
		$fields = self::$fields;

		// Cannot modify existing LogRecord, so we'll make a copy with the changes we want
		$newContext = $record->context;
		$newExtra = $record->extra;

		foreach ($fields as $field) {
			if (isset($newContext[$field])) {
				$newExtra[$field] = $newContext[$field];
				unset($newContext[$field]);
			}
		}

		// Correlate with the active OpenTelemetry span, if any
		$spanContext = Span::getCurrent()->getContext();
		if ($spanContext->isValid()) {
			$newExtra['trace_id'] = $spanContext->getTraceId();
			$newExtra['span_id'] = $spanContext->getSpanId();
		}

		return new LogRecord(
			$record->datetime,
			$record->channel,
			$record->level,
			$record->message,
			$newContext,
			$newExtra,
			$record->formatted
		);
	}
}
