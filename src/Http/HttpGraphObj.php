<?php

namespace PSP\Http;

use PSP\Log\Log;

class HttpGraphObj
{
	private $query_obj_list;
	private $fragments;
	private $variables;
	private $template_lib;
	private string $library_path = __DIR__ . DIRECTORY_SEPARATOR . 'GraphQL' . DIRECTORY_SEPARATOR;
	
	private Log $log;
	public string $type;
	public string $name;
	private string|bool $loaded_query_template;
	
	/**
	 * @param string $type query, mutation or subscription
	 * @param string $name name of the query
	 * @param Log $log
	 */
	public function __construct(string $type, string $name, Log $log)
	{
		$this->type = strtolower($type);
		$this->name = $name;
		$this->log = $log;
		
		$this->query_obj_list = [];
		$this->fragments = [];
		$this->variables = [];
		
		$this->loaded_query_template = false;
	}
	
	/**
	 * Adds a fragment to the fragment stack
	 * @param string $name The fragment name that will be referenced in the query
	 * @param string|string[] $fragment Array of strings or 2D array
	 * @param string $target_field The field type that this fragment is associated with e.g. OrderMutationOutput.
	 *           See system's schema for help identifying
	 * @param string $type Accepts 'args' or 'return_fields'. How should the fragment be formatted?
	 */
	public function add_fragment(string $name, string|array $fragment, string $target_field, string $type = 'return_fields'): void
	{
		$str = "fragment $name on $target_field { ";
		
		if ($type == 'args') {
			$str .= $this->build_arguments($fragment);
		}
		elseif ($type == 'return_fields') {
			$str .= $this->build_return_fields($fragment);
		}
		
		$this->fragments[$name] = $str . "} ";
	}
	
	/**
	 * Adds a fragment to the fragment stack from an already formatted string.
	 * This bypasses any query structure formattting on the fragment.
	 * @param string $name The fragment name
	 * @param string $template_name The filename of template, if different from $name. Exclude template type and file type
	 * @param string $path_override OPTIONAL the path to the directory containing the template
	 * @return boolean True on success
	 */
	public function add_fragment_from_template(string $name, string $template_name = '', string $path_override = ''): bool
	{
		if (empty($template_name)) {
			$template_name = $name;
		}
		
		$fragment = $this->read_template("fragment.$template_name", $path_override);
		
		if (empty($fragment)) {
			return false;
		}
		
		$this->fragments[$name] = $this->validate_template($fragment);
		return true;
	}
	
	/**
	 * Loads the query string. Calling this again will ovewrite the previously
	 * stored query.
	 * @param string $template_name The filename of template excluding emplate type and file type
	 * @param string $path_override OPTIONAL the path to the directory containing the template
	 * @return bool True on success
	 */
	public function add_query_from_template(string $template_name, string $path_override = ""): bool
	{
		if (empty($template_name) || gettype($template_name) != 'string') {
			return false;
		}
		
		$query = $this->read_template("{$this->type}.$template_name", $path_override);
		
		if (empty($query)) {
			// Failed to load file
			return false;
		}
		
		$this->loaded_query_template = $this->validate_template($query);
		return true;
	}
	
	/**
	 * Constructs the query and adds it to the query stack
	 * @param string $object_name
	 * @param array $args Can pass empty array
	 * @param array|string $return_fields
	 * @param string $alias OPTIONAL If multiple queries are being performed using
	 *          the same object then aliases are required to avoid naming conflicts
	 */
	public function add_query(string $object_name, array $args, array|string $return_fields, string $alias = ''): void
	{
		$query_obj = $this->build_query_object($object_name, $args, $return_fields, $alias);
		
		$this->query_obj_list[] = $query_obj;
	}
	
	/**
	 * Adds a variable to the query variable stack. Overwrites duplicate keys
	 * @param string $key
	 * @param string $type
	 * @param string $val
	 */
	public function add_variable(string $key, string $type, string $val): void
	{
		$this->variables[$key] = [$type, $val];
	}
	
	
	/**
	 * Pass an array of arrays add. Overwrites duplicate keys
	 * @param array $variables [ 'key' => ['type', 'value'] ]
	 */
	public function add_variables(array $variables)
	{
		$this->variables = array_merge($this->variables, $variables);
	}
	
	/**
	 * Creates a string formatted for Graph Query Language from provided array
	 * @param array|string $args Should be key => val pairs
	 * @param bool $root Is this array at root? Should almost always be left at true
	 *          unless it's passed from inside of build_arguments()
	 * @param bool|null $is_keyed OPTIONAL Is the array of arguments sequential or keyed?
	 * @return string Includes braces
	 */
	private function build_arguments(array|string $args, bool $root = true, ?bool $is_keyed = null): string
	{
		if (gettype($args) == 'string') {
			return "$args ";
		}
		elseif (gettype($args) != 'array') {
			$this->log->error("Invalid type passed to build_arguments. Expected array|string, got " . gettype($args));
			throw new \InvalidArgumentException("Invalid type passed to build_arguments. Expected array|string, got " . gettype($args));
		}
		
		if (!isset($is_keyed)) {
			$is_keyed = $this->is_keyed($args);
		}
		
		$ret = "";
		foreach ($args as $key => $val) {
			$str = $is_keyed ? $this->validate_alias($key) . ':' : "";
			
			switch (gettype($val)) {
				case 'string':
					if (strpos($val, '_$') === 0) {
						$str .= $this->validate_value(substr($val, 1));
					}
					else {
						$str .= '"' . $this->validate_value($val) . '"';
					}
					break;
				case 'double':
				case 'integer':
					$str .= ((string)$val) . '';
					break;
				case 'boolean':
					$str .= $val ? 'true' : 'false';
					break;
				case 'array':
					$str .= $this->build_arguments($val, false);
					break;
				default:
					// Skip it!
					continue 2;
			}
			$ret .= $str . " ";
		}
		
		if (!$root) {
			// GraphQL follows JavaScript notation and uses [] for sequential arrays and {} for objects/keyed arrays
			$ret = $is_keyed ? " { $ret } " : " [ $ret ] ";
		}
		return $ret;
	}
	
	/**
	 * Constructs a query object and returns the formatted string
	 * @param string $object_name
	 * @param array $args Can pass empty array
	 * @param array|string $return_fields
	 * @param string $alias OPTIONAL If multiple queries are being performed using
	 *          the same object then aliases are required to avoid naming conflicts
	 * @return string
	 */
	private function build_query_object(string $object_name, array $args, array|string $return_fields, string $alias = ''): string
	{
		$ret = "";
		
		if (!empty($alias)) {
			$ret .= $this->validate_alias($alias) . ":";
		}
		
		$ret .= $object_name . "(" . $this->build_arguments($args) . ")";
		$ret .= $this->build_return_fields($return_fields);
		
		return $ret;
	}
	
	/**
	 * Creates a string formatted for Graph Query Language from
	 * provided array
	 * @param array|string $fields Should be string or array of strings. Allows for multidimensional arrays
	 * @return string Includes braces {}
	 */
	private function build_return_fields(array|string $fields): string
	{
		if (gettype($fields) == 'string') {
			return "{ $fields } ";
		}
		elseif (gettype($fields) != 'array') {
			$this->log->error("Invalid type passed to build_return_fields. Expected array|string, got " . gettype($fields));
			throw new \InvalidArgumentException("Invalid type passed to build_return_fields. Expected array|string, got " . gettype($fields));
		}
		
		$ret = "{ ";
		foreach ($fields as $field) {
			$ret .= gettype($field) == 'string' ? trim($field) : $this->build_return_fields($field);
		}
		$ret .= "} ";
		
		return $ret;
	}
	
	
	/**
	 * Creates and returns the full graph query string
	 * @return string Full GraphQL query including fragments
	 */
	public function get_query(): string
	{
		if (!$this->loaded_query_template || gettype($this->loaded_query_template) != "string") {
			$query = "{$this->type} {$this->name} ";
			
			if (!empty($this->variables)) {
				$query .= "(";
				
				foreach ($this->variables as $key => $arr) {
					$query .= '$' . "$key: $arr[0] ";
				}
				
				$query .= ") ";
			}
			
			$query .= "{ "
				. implode(" ", $this->query_obj_list)
				. "} "
				. implode(" ", $this->fragments);
			
			return $query;
		}
		else {
			return $this->loaded_query_template;
		}
	}
	
	/**
	 * Returns keyed array of variables
	 * @param bool $include_types Optionally include variable types
	 * @return array [ key => value ]
	 */
	public function get_variables(bool $include_types = false): array
	{
		if ($include_types) {
			$arr = $this->variables;
		}
		else {
			$arr = array_map(
				function ($var) {
					return $var[1];
				},
				$this->variables
			);
		}
		
		return $arr;
	}
	
	public static function validate_alias(string $str)
	{
		// Aliases cannot be purely numerical
		return preg_match('/^\d/', $str) === 1 ? "_$str" : $str;
	}
	
	public static function validate_value(string $val): string
	{
		// Remove escape characters
		$val = str_replace(['\r', '\n', '\\'], "", $val);
		
		// Normalize spaces
		$val = preg_replace('/\s+/', ' ', $val);
		
		// Filter for acceptable characters
		$val = preg_replace('/[^A-Za-z0-9\- :.+=()#|]\p{Arabic}/', "", $val);
		
		// Escape double quotes only
		return preg_replace('/"/', '\"', $val);
	}
	
	public static function validate_template(string $str): string
	{
		// Replaces tabs and carriage returns with spaces (Does not replace newlines)
		return preg_replace('/\t+|\r+/', " ", trim($str));
	}
	
	/**
	 * Returns if an array is keyed or sequential
	 * @param array $arr
	 * @return bool True is a keyed array, false on sequential
	 */
	public static function is_keyed(array $arr): bool
	{
		return array_keys($arr) !== range(0, count($arr) - 1);
	}
	
	/**
	 * Parses a GQL template file into a string
	 * @param string $template_name The filename of template, excluding .gql
	 * @param string $path_override OPTIONAL overrides the object's path
	 * @return string|null
	 */
	private function read_template(string $template_name, string $path_override = ""): ?string
	{
		// Caching makes the world go round! Also filesystems are slow
		if (isset($this->template_lib[$template_name])) {
			return $this->template_lib[$template_name];
		}
		
		$path = empty($path_override) ? $this->library_path : $path_override;
		$template = file_get_contents($path . DIRECTORY_SEPARATOR . "$template_name.gql");
		
		if (empty($template)) {
			$this->log->error("Failed to open up template: $template_name. Path: "
				. (empty($path_override) ? $this->library_path : $path_override));
			return null;
		}
		
		$this->template_lib[$template_name] = $template;
		return $this->template_lib[$template_name];
	}
	
	/**
	 * Replaces the generic GraphQL library path for loading templates
	 * Generic: /lib/GraphQL
	 * @param string $path The path to the library
	 */
	public function set_library_path(string $path): void
	{
		if (!empty($path) && is_dir($path)) {
			$this->library_path = $path;
		}
	}
}
