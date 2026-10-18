<?php

namespace PSP\Db;

use Iterator;
use PSP\Log\Log;

/**
 * @template D of DBTable
 * @implements Iterator<int, D>
 */
class DBTableIterator implements Iterator
{
	/**
	 * @var class-string<D>
	 */
	private string $class_name;
	private array $where;
	private string $orderby;
	private int $load_rules;
	private int $limit;
	private int $key = 0;
	/** @var array<string, string> Ordered map of cursor field name => comparison operator ('<' or '>') */
	private array $cursor_fields = [];
	/**
	 * @var D|null
	 */
	private DBTable|array|null $current_item = null;
	/** @var array @var Buffer of records loaded from DB stored in reverse order */
	private array $reverse_buffer = [];
	private bool $is_buffer_empty = true;
	private bool $final_page_reached = false;
	/** @var array<string, mixed>|null Last record's value for each cursor field, keyed by field name. NULL until the first page has loaded */
	private ?array $last_orderby_values = null;

	/**
	 * @param class-string<D> $class_name DBTable object name
	 * @param mixed[] $where Associative array of where parameters. Use :NULL: for 'IS NULL', :NOTNULL: for 'IS NOT NULL'. Pass an array of values for an IN clause. For an op other than equals, pass a value of: ['op' => '>', 'val' => $value]
	 * @param string $orderby Column(s) to order by, used for cursor based keyset pagination. Include ASC/DESC in each. Comma-separate for multiple columns. Example: order_pk ASC or priority DESC, order_pk ASC
	 * @param int $limit Page size limit
	 * @param int $load_rules DBTable::LOAD_XXX
	 * @throws \Exception Throws exception on conflicting $where and $orderby clauses
	 */
	public function __construct(string $class_name, array $where, string $orderby, int $limit = 50, int $load_rules = DBTable::LOAD_DEFAULT)
	{
		// Setup cursor info based on orderby. Supports one or more comma-separated "field DIRECTION" clauses
		foreach (explode(',', $orderby) as $part) {
			$part = trim($part);
			if ($part === '') {
				continue;
			}
			$pieces = preg_split('/\s+/', $part);
			if (count($pieces) < 2 || empty($pieces[1])) {
				Log::error("FIXME: invalid orderby passed by DBTableIterator class $class_name: $orderby");
				continue;
			}
			[$field, $direction] = $pieces;
			$this->cursor_fields[$field] = strtoupper($direction) === 'DESC' ? '<' : '>';
		}

		// Make sure $where clause and $orderby don't conflict
		foreach ($this->cursor_fields as $field => $direction) {
			if (isset($where[$field]) && $where[$field] !== ':NOTNULL:') {
				// Must have an op value for cursor to work
				if (!isset($where[$field]['op'])) {
					$msg = "Where clause field conflicts with orderby $field";
					Log::error('FIXME: '.$msg);
					throw new \Exception('DBTableIterator '.$msg);
				}
				if ($where[$field]['op'][0] != $direction) {
					$msg = "Where clause field op ({$where[$field]['op']}) conflicts with orderby ordering ($orderby)";
					Log::error('FIXME: '.$msg);
					throw new \Exception('DBTableIterator '.$msg);
				}
			}
		}

		$this->class_name = $class_name;
		$this->where = $where;
		$this->orderby = $orderby;
		$this->load_rules = $load_rules;
		$this->limit = $limit;
	}

	/**
	 * Build the keyset-pagination seek predicate for all cursor fields as a raw SQL fragment.
	 * For fields F1..Fn (in orderby priority) with directions d1..dn and last-seen values v1..vn, builds:<br>
	 * (F1 d1 v1) OR (F1 = v1 AND F2 d2 v2) OR (F1 = v1 AND F2 = v2 AND F3 d3 v3) OR ...<br>
	 * This correctly skips past the last page without dropping or repeating rows that tie on leading fields.
	 * @return array{sql:string, params:mixed[]} To be passed to DBTable as $raw_where value
	 */
	private function build_seek_predicate(): array
	{
		$fields = array_keys($this->cursor_fields);
		$clauses = [];
		$params = [];
		foreach ($fields as $i => $field) {
			$parts = [];
			for ($j = 0; $j < $i; $j++) {
				$eq_field = $fields[$j];
				$parts[] = "$eq_field = ?";
				$params[] = $this->last_orderby_values[$eq_field];
			}
			$parts[] = "$field {$this->cursor_fields[$field]} ?";
			$params[] = $this->last_orderby_values[$field];
			$clauses[] = '('.implode(' AND ', $parts).')';
		}
		return ['sql' => implode(' OR ', $clauses), 'params' => $params];
	}

	private function fetch_next_page(): void
	{
		// Do nothing if we've already loaded the last page of records
		if ($this->final_page_reached) {
			return;
		}
		
		// Build seek predicate from cursor fields for pages past the first
		$raw_where = ($this->last_orderby_values !== null) ? $this->build_seek_predicate() : null;

		$class = $this->class_name;
		$records = $class::load_from(
			$this->where,
			$this->orderby,
			$this->limit,
			$this->load_rules,
			$raw_where
		);

		// Special handling for limit=1
		if ($this->limit === 1 && !empty($records)) {
			$records = [$records];
		}

		if (!empty($records)) {
			if (count($records) < $this->limit) {
				$this->final_page_reached = true;
			}
			$last_record = end($records);
			$this->reverse_buffer = array_reverse($records);
			$this->is_buffer_empty = false;
			$this->last_orderby_values = [];
			foreach ($this->cursor_fields as $field => $direction) {
				$this->last_orderby_values[$field] = ($this->load_rules & DBTable::LOAD_AS_ARRAY) ? $last_record[$field] : $last_record->{$field};
			}
		}
		else {
			$this->final_page_reached = true;
			$this->is_buffer_empty = true;
		}
	}

	/**
	 * @return D|array|null
	 */
	public function current(): DBTable|array|null
	{
		return $this->current_item;
	}

	public function next(): void
	{
		// Load next page if buffer is empty
		if ($this->is_buffer_empty) {
			$this->fetch_next_page();
		}
		if (!$this->is_buffer_empty) {
			$this->current_item = array_pop($this->reverse_buffer);
			if (empty($this->reverse_buffer)) {
				$this->is_buffer_empty = true;
			}
			$this->key++;
		}
		else {
			$this->current_item = null;
		}
	}

	public function key(): int
	{
		return $this->key;
	}

	public function valid(): bool
	{
		return ($this->current_item !== null);
	}

	public function rewind(): void
	{
		$this->key = 0;
		$this->last_orderby_values = null;
		$this->reverse_buffer = [];
		$this->is_buffer_empty = true;
		$this->final_page_reached = false;
		$this->fetch_next_page();
		$this->current_item = array_pop($this->reverse_buffer);
		if (empty($this->reverse_buffer)) {
			$this->is_buffer_empty = true;
		}
	}
}
