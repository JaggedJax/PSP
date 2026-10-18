<?php

namespace PSP\Process;

/**
 * Singleton class<br>
 * Usage: First call CLI::setOptions()<br>
 * Then Call CLI::get() to read set CLI options
 */
class CLI
{
	private static ?self $instance = null;

	/** @var array<string, string> $short_options */
	private array $short_options = [];
	/** @var array<string, string> $long_options */
	private array $long_options = [];
	private string $summary = '';

	/** @var array<string, string> $short_to_long Map short option to matching long option */
	private array $short_to_long = [];
	/** @var array<string, string> $long_to_short Map long option to matching short option */
	private array $long_to_short = [];

	/** @var array<string, string> $selected_options Key/Value response from getopt() */
	private $selected_options = [];
	
	public const CHECK = "\xE2\x9C\x94"; // Check mark unicode
	
	public const REDX = "\xE2\x9D\x8C"; // Red X unicode
	
	public static $foreground_colors = [
		'black' 		=> '0;30',
		'dark_grey' 	=> '1;30',
		'red' 			=> '0;31',
		'green'			=> '0;32',
		'light_green'	=> '1;32',
		'brown'			=> '0;33',
		'yellow'		=> '1;33',
		'blue'			=> '0;34',
		'light_blue'	=> '1;34',
		'magenta'		=> '0;35',
		'light_magenta'	=> '1;35',
		'cyan'			=> '0;36',
		'light_cyan'	=> '1;36',
		'light_grey'	=> '0;37',
		'white'			=> '1;37',
	];
	
	public static $background_colors = [
		'black'		=> '40',
		'red'		=> '41',
		'green'		=> '42',
		'yellow'	=> '43',
		'blue'		=> '44',
		'magenta'	=> '45',
		'cyan'		=> '46',
		'light_grey'	=> '47'
	];

	private function __construct()
	{
	}

	private static function getInstance(): CLI
	{
		if (self::$instance == null) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Formats text for colored output and echos it
	 * @param string $str
	 * @param string $text_color Desired text color. One of keys from CLI::$foreground_colors
	 * @param string $bg_color Desired background color. One of keys from CLI::$background_colors
	 * @return void
	 */
	public static function echo(string $str, string $text_color = '', string $bg_color = ''): void
	{
		echo static::color($str, $text_color, $bg_color);
	}
	
	/**
	 * Formats an output string for terminal with text and background color
	 * @param string $str
	 * @param string $text Desired text color. One of CLI::$foreground_colors
	 * @param string $bg Desired background color. One of CLI::$background_colors
	 * @return string
	 */
	public static function color(string $str, string $text = '', string $bg = ''): string
	{
		if (!empty($text)) {
			$text = strtolower($text);
			
			if (empty(static::$foreground_colors[$text])) {
				$text = static::$foreground_colors['white'];
			}
			else {
				$text = static::$foreground_colors[$text];
			}
		}
		else {
			$text = '';
		}
		
		if (!empty($bg)) {
			$bg = strtolower($bg);
			
			if (empty(static::$background_colors[$bg])) {
				$bg = '';
			}
			elseif ($text) {
				$bg = ';' . static::$background_colors[$bg];
			}
			else {
				$bg = static::$background_colors[$bg];
			}
		}
		else {
			$bg = '';
		}
		
		$prefix = '';
		$suffix = '';
		
		if ($text || $bg) {
			$prefix = "\e[" . $text . $bg . "m";
			$suffix = "\e[0m";
		}
		
		return $prefix . $str . $suffix;
	}

	/**
	 * Set and parses the CLI Options. Follow key with a colon to indicate it requires a value. Follow key with two colons to indicate a value is optional<br>
	 * ALWAYS assumes that -h and --help should print out help message and exit immediately
	 * @param array<string, string> $short Key is option, Value is description
	 * @param array<string, string> $long Key is option, Value is description. Start with "-x " to match it with a short option
	 * @return void
	 */
	public static function setOptions($short = [], $long = [], $summary = ''): void
	{
		$cli = self::getInstance();

		if (empty($short['h'])) {
			$short['h'] = 'Prints Help Info';
		}
		if (empty($long['help'])) {
			$long['help'] = '-h Prints This Help Message';
		}

		$cli->short_options = $short;
		$cli->long_options = $long;
		$cli->summary = $summary;

		$cli->selected_options = getopt(implode('', array_keys($short)), array_keys($long));

		$cli->matchOptions();

		if (CLI::get('help')) {
			echo CLI::generateHelpMessage();
			exit(0);
		}
	}

	/**
	 * Get provided CLI option. Checks both long and short versions automatically<br>
	 * NOTICE: Returns TRUE for flags with no value
	 * @param string $option CLI Option to get. Checks both long and short versions automatically.
	 * @param bool|string|null $default Default value to return if not found in provided options
	 * @return bool|string|null NOTICE: Returns TRUE for flags with no value
	 */
	public static function get(string $option, mixed $default = null): bool|string|null
	{
		$cli = self::getInstance();

		if (strlen($option) > 1) {
			$long = $option;
			$short = $cli->long_to_short[$option] ?? null;
		}
		else {
			$short = $option;
			$long = $cli->short_to_long[$option] ?? null;
		}

		// Get from long option
		if (!empty($long) && isset($cli->selected_options[$long])) {
			$value = $cli->selected_options[$long];
			// Convert existing flags from false to true
			if ($value === false) {
				$value = true;
			}
		}
		// Get from short option if not in long options
		elseif (!empty($short) && isset($cli->selected_options[$short])) {
			$value = $cli->selected_options[$short];
			// Convert existing flags from false to true
			if ($value === false) {
				$value = true;
			}
		}

		if (!isset($value)) {
			$value = $default;
		}

		return $value;
	}

	/**
	 * Match up short and long options to allow using either for lookup
	 * @return void
	 */
	private function matchOptions(): void
	{
		if (empty($this->short_options) || empty($this->long_options)) {
			return;
		}

		foreach (self::getInstance()->long_options as $long_opt => $description) {
			// Strip off any trailing colons for mapping
			$long_opt = rtrim($long_opt, ':');

			// If description starts with "-X " then treat that as the shortcode mapping
			if ($description[0] === '-' && $description[2] === ' ') {
				$this->long_to_short[$long_opt] = $description[1];
				$this->short_to_long[$description[1]] = $long_opt;
			}
			// Otherwise, assume starting char of long matches the short option
			elseif (!empty($this->short_options[$long_opt[0]])) {
				$this->long_to_short[$long_opt] = $long_opt[0];
				$this->short_to_long[$long_opt[0]] = $long_opt;
			}
		}
	}


	/**
	 * Generates a formatted help message from getopt() option configurations.<br>
	 * This assumes short options match a long option starting with the same char
	 *
	 * Get a clean user-friendly man page of all the options available for this script
	 * @return string The formatted help message.
	 */
	public static function generateHelpMessage(): string
	{
		$optionsMap = [];
		$descriptions = [];

		// 1. Parse long options to establish the primary mapping
		foreach (self::getInstance()->long_options as $long_opt => $description) {
			$valueType = 'none'; // A flag with no value
			if (str_ends_with($long_opt, '::')) {
				$valueType = 'optional'; // Value is optional
				$name = rtrim($long_opt, ':');
			}
			elseif (str_ends_with($long_opt, ':')) {
				$valueType = 'required'; // Value is required
				$name = rtrim($long_opt, ':');
			}
			else {
				$name = $long_opt;
			}

			$short = null;

			// If description starts with "-X " then treat that as the shortcode mapping
			if ($description[0] === '-' && $description[2] === ' ') {
				$short = $description[1];
				$description = substr($description, 3);
			}

			$optionsMap[$name] = ['long' => $name, 'short' => $short, 'value' => $valueType];
			$descriptions[$name] = $description;
		}

		// 2. Parse short options and link them to existing long options
		//    This assumes a convention like 's' maps to a long option starting with 's'
		foreach (self::getInstance()->short_options as $short_option => $description) {
			$char = $short_option[0];
			if (!ctype_alpha($char)) { // Skip non-alpha characters
				continue;
			}

			$valueType = 'none';
			// Check for colons to determine value requirement
			if (isset($short_option[1]) && $short_option[1] === ':') {
				if (isset($short_option[2]) && $short_option[2] === ':') {
					$valueType = 'optional';
				}
				else {
					$valueType = 'required';
				}
			}

			// Find a matching long option to pair with
			$foundMatch = false;
			// Find exact match
			foreach ($optionsMap as $longName => $details) {
				if ($details['short'] == $char) {
					$foundMatch = true;
					break;
				}
			}

			if (!$foundMatch) {
				// Find match by long name starting character
				foreach ($optionsMap as $longName => $details) {
					if ($details['short'] === null && str_starts_with($longName, $char)) {
						$optionsMap[$longName]['short'] = $char;
						$foundMatch = true;
						break;
					}
				}
			}

			if (!$foundMatch) {
				$optionsMap["_short_{$char}"] = ['long' => null, 'short' => $char, 'value' => $valueType];
			}
			$descriptions[$char] = $description;
		}

		// 3. Build the formatted output string
		$output = "Usage: php {$_SERVER['argv'][0]} [options]\n\n";

		$summary = self::getInstance()->summary;
		if (!empty($summary)) {
			$output .= "Description:\n" . $summary . "\n\n";
		}

		$output .= "Options:\n";

		$lines = [];
		$maxOptionStrLength = 0;

		// First, generate the option strings to calculate alignment padding
		foreach ($optionsMap as $key => $details) {
			$optStr = '  '; // Indent
			if ($details['short']) {
				$optStr .= '-' . $details['short'];
			}

			switch ($details['value']) {
				case 'required':
					$optStr .= ' <value>';
					break;
				case 'optional':
					$optStr .= ' [<value>]';
					break;
			}

			if ($details['short'] && $details['long']) {
				$optStr .= ', ';
			}
			if ($details['long']) {
				$optStr .= '--' . $details['long'];
			}

			switch ($details['value']) {
				case 'required':
					$optStr .= '=<value>';
					break;
				case 'optional':
					$optStr .= '[=<value>]';
					break;
			}

			$lines[$key] = $optStr;
			if (strlen($optStr) > $maxOptionStrLength) {
				$maxOptionStrLength = strlen($optStr);
			}
		}

		// Now, build the final output with aligned descriptions
		foreach ($optionsMap as $key => $details) {
			$optStr = $lines[$key];
			$padding = str_repeat(' ', $maxOptionStrLength - strlen($optStr) + 4);
			$description = $descriptions[$details['long'] ?? $details['short']] ?? '';
			$output .= $optStr . $padding . $description . "\n";
		}

		return $output;
	}

	/**
	 * Resets the singleton instance. Primarily for testing purposes.
	 */
	public static function reset(): void
	{
		self::$instance = null;
	}

	/**
	 * Inject options into the list to simulate values being provided to the CLI
	 * @param array<string, mixed> $selected_options Example: ['s' => '123']
	 * @return void
	 */
	public static function inject(array $selected_options)
	{
		self::getInstance()->selected_options = array_merge(self::getInstance()->selected_options, $selected_options);
	}

}
