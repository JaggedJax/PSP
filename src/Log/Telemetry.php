<?php

namespace PSP\Log;

use OpenTelemetry\API\Trace\SpanInterface;

/**
 * Helpers for tagging the request/job root OpenTelemetry span with common IDs.
 * Everything here is a safe no-op if tracing is disabled or no root span was started.
 */
class Telemetry
{
	/** @var list<string> IDs worth tagging on spans. Keys are the same names used by Log::set_element() and route arguments */
	private static array $taggable_ids = ['environment'];
	/** Prefix for span attribute names. EG: "app" gives "app.{key}" */
	public static string $attribute_prefix = 'app';

	private static ?SpanInterface $root_span = null;

	/**
	 * Add app-specific IDs to tag on spans. EG: customer_id
	 * @param string ...$ids
	 */
	public static function add_taggable_ids(string ...$ids): void
	{
		self::$taggable_ids = array_values(array_unique(array_merge(self::$taggable_ids, $ids)));
	}

	/**
	 * Get the IDs tagged on spans
	 * @return list<string>
	 */
	public static function taggable_ids(): array
	{
		return self::$taggable_ids;
	}

	/**
	 * Register the root span for this request/job. Called from autoload.php
	 */
	public static function set_root_span(?SpanInterface $span): void
	{
		self::$root_span = $span;
	}

	/**
	 * Tag the root span with an ID as attribute "{prefix}.{key}". Non-taggable keys and empty/non-scalar values are ignored.
	 * Spans cannot have attributes removed, so the last value set wins.
	 */
	public static function tag(string $key, mixed $value): void
	{
		if (self::$root_span === null || !in_array($key, self::$taggable_ids, true) || !is_scalar($value) || $value === '') {
			return;
		}
		if (is_string($value) && ctype_digit($value)) {
			$value = (int)$value;
		}
		self::$root_span->setAttribute(self::$attribute_prefix . ".$key", $value);
	}
}
