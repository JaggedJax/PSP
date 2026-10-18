<?php

namespace PSP\Util;

use PSP\Log\Log;

class Strings
{
	/**
	 * Checks if a variable can be converted to a string w/o an error
	 * @param mixed $var
	 * @return bool True if the var can be cast to a string
	 */
	public static function can_be_string(mixed $var): bool
	{
		return $var === null || is_scalar($var) || (is_object($var) && method_exists($var, '__toString'));
	}
	
	/**
	 * String to turn into URL slug
	 * @param string $string
	 * @return string
	 */
	public static function slugify(string $string): string
	{
		$slug = strtolower(trim($string));
		$slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
		$slug = preg_replace('/-+/', '-', $slug);
		return trim($slug, '-');
	}
	
	/**
	 * Find closest matching string using Levenshtein distance. Case insensitive.
	 * Rejects matches with 0 characters in common or below the $min_match_percent. If no match found: "distance" will be -1, and "closest" will contain the input string
	 * @param string $input_word Word to compare against array
	 * @param string[] $compare_array Array of strings. Closest match from here returned.
	 * @param int $min_match_percent Example: 50 will only return results with at least 50% of characters matching the "compare" string. Percent is calculated for each string in the compare array. A match is not guaranteed. Pass null to ignore
	 * @return array{closest:string, distance:int}
	 */
	public static function levenshtein(string $input_word, array $compare_array, int $min_match_percent = 30): array
	{
		// -1 Means no match found
		$shortest = -1;
		$closest = $input_word;
		// loop through words to find the closest
		foreach ($compare_array as $word) {
			// Calculate maximum distance allowed based on string we are checking against
			$str_len = strlen($word);
			$max_distance_chars = !empty($min_match_percent) ? $str_len - floor($str_len * ($min_match_percent / 100)) : null;
			
			// Calculate the distance between the input word and the current word
			$lev = levenshtein(strtoupper($input_word), strtoupper($word));
			
			// Check for exact match
			if ($lev == 0) {
				$closest = $word;
				$shortest = 0;
				// Found an exact match, we're done
				break;
			}
			
			// if this distance is less than the next found shortest
			// distance, OR if a next shortest word has not yet been found
			// DOES NOT COUNT matches with less than $max_distance_chars characters in common
			if (($lev <= $shortest || $shortest < 0) && (empty($max_distance_chars) || $lev <= $max_distance_chars)) {
				// set the closest match, and shortest distance
				$closest = $word;
				$shortest = $lev;
			}
		}
		
		return ['closest' => $closest, 'distance' => $shortest];
	}
	
	/**
	 * Swap European characters with their English ascii equivalent
	 * @param string $input String to transliterate
	 * @param bool $strip_non_ascii Should non-ascii characters be removed?
	 * @return string
	 */
	public static function transliterateEuroCharacters(string $input, bool $strip_non_ascii = false): string
	{
		$translate = [
			'À' => 'A',
			'Á' => 'A',
			'Â' => 'A',
			'Ã' => 'A',
			'Ä' => 'A',
			'Å' => 'A',
			'Æ' => 'A',
			'à' => 'a',
			'á' => 'a',
			'â' => 'a',
			'ã' => 'a',
			'ä' => 'a',
			'å' => 'a',
			'æ' => 'a',
			'ß' => 'Ss',
			'Þ' => 'B',
			'þ' => 'b',
			'Ç' => 'C',
			'Č' => 'C',
			'Ć' => 'C',
			'č' => 'c',
			'ć' => 'c',
			'ç' => 'c',
			'Đ' => 'Dj',
			'Ð' => 'Dj',
			'đ' => 'dj',
			'È' => 'E',
			'É' => 'E',
			'Ê' => 'E',
			'Ë' => 'E',
			'è' => 'e',
			'é' => 'e',
			'ê' => 'e',
			'ë' => 'e',
			'ƒ' => 'f',
			'Ì' => 'I',
			'Í' => 'I',
			'Î' => 'I',
			'Ï' => 'I',
			'ì' => 'i',
			'í' => 'i',
			'î' => 'i',
			'ï' => 'i',
			'ı' => 'i',
			'Ñ' => 'N',
			'ñ' => 'n',
			'Ò' => 'O',
			'Ó' => 'O',
			'Ô' => 'O',
			'Õ' => 'O',
			'Ö' => 'O',
			'Ø' => 'O',
			'Œ' => 'OE',
			'ð' => 'o',
			'ò' => 'o',
			'ó' => 'o',
			'ô' => 'o',
			'õ' => 'o',
			'ö' => 'o',
			'ø' => 'o',
			'œ' => 'oe',
			'Ŕ' => 'R',
			'ŕ' => 'r',
			'Š' => 'S',
			'š' => 's',
			'Ù' => 'U',
			'Ú' => 'U',
			'Û' => 'U',
			'Ü' => 'U',
			'ù' => 'u',
			'ú' => 'u',
			'û' => 'u',
			'ü' => 'u',
			'Ý' => 'Y',
			'Ÿ' => 'Y',
			'ý' => 'y',
			'ÿ' => 'y',
			'Ž' => 'Z',
			'ž' => 'z',
		];
		
		$new_str = strtr($input, $translate);
		
		if ($strip_non_ascii) {
			$new_str = @preg_replace("/[^[:ascii:]]+/", '', $new_str);
		}
		
		return $new_str;
	}
	
	/**
	 * Convert a Callable into a readable/loggable string
	 * @param callable $callable
	 * @return string
	 */
	public static function callable_as_string(callable $callable): string
	{
		return match (true) {
			is_string($callable) && strpos($callable, '::') => '[static] ' . $callable,
			is_string($callable) => '[function] ' . $callable,
			is_array($callable) && is_object($callable[0]) => '[method] ' . get_class($callable[0]) . '->' . $callable[1],
			is_array($callable) => '[static] ' . $callable[0] . '::' . $callable[1],
			$callable instanceof \Closure => '[closure]',
			is_object($callable) => '[invokable] ' . get_class($callable),
			default => '[unknown]',
		};
	}
	
	/**
	 * Prepare text for output to web<br>
	 *    - Converts html entities to & codes<br>
	 *    - Converts newlines to 'br'<br>
	 *    - Converts tabs to 4 spaces<br>
	 * @param string $string_to_prepare
	 * @return string
	 */
	public static function for_web(string $string_to_prepare): string
	{
		return preg_replace('/(\t)/', '&nbsp;&nbsp;&nbsp;&nbsp;', nl2br(htmlentities($string_to_prepare)));
	}
	
	/**
	 * Remove invalid characters like slashes from filenames. Do not pass full file path.
	 * @param string $filename DO NOT INCLUDE FILE PATH
	 * @return string Safe filename
	 */
	public static function safe_filename(string $filename): string
	{
		$replacements = [
			' ' => '_',
			'/' => '-',
			'\r' => '',
			'\n' => '',
			'\\' => '',
			'..' => ''
		];
		
		return str_replace(array_keys($replacements), array_values($replacements), $filename);
	}
	
	/**
	 * Check if a sting is over 10MB and doubles the process memory limit if there's not at least 8x memory available<br>
	 * Helps with processing of large JSON strings before decoding them<br>
	 * Will only increase memory 1 time per process
	 *
	 * @param string $string
	 * @return bool Was limit increased?
	 */
	public static function increaseMemoryLimitOnLargeString(string $string): bool
	{
		$strlen_bytes = strlen($string);
		
		// If string is less than ~10MB, no need to increase memory
		// Only ever increase memory once
		if (!empty($GLOBALS['proc_mem_limit_increased']) || $strlen_bytes < 10000000) {
			return false;
		}
		
		// Convert current memory limit to bytes
		$currentLimit = ini_get('memory_limit');
		$currentLimitBytes = self::memoryStringToBytes($currentLimit);
		
		// Get current in-use memory in bytes
		$usedMemory = memory_get_usage(true);
		
		// Calculate available memory in MB
		$availableMemoryBytes = $currentLimitBytes - $usedMemory;
		
		// If available memory < 8x the string size, double the memory limit
		if ($availableMemoryBytes < ($strlen_bytes * 8)) {
			// Double the current limit
			$newLimitBytes = $currentLimitBytes * 2;
			
			// Convert back to a string format with proper suffix (K, M, G)
			$newLimit = self::formatBytes($newLimitBytes);
			
			// Set new memory limit
			ini_set('memory_limit', $newLimit);
			$GLOBALS['proc_mem_limit_increased'] = true;
			Log::error("FIXME: Memory limit increased from $currentLimit to $newLimit");
			
			return true;
		}
		
		return false;
	}
	
	/**
	 * Converts memory limit string (like '256M') to bytes
	 *
	 * @param string $memoryLimit
	 * @return int Memory limit in bytes
	 */
	
	public static function memoryStringToBytes(string $memoryLimit): int
	{
		$value = (int)$memoryLimit;
		$lastChar = strtolower(substr($memoryLimit, -1));
		
		switch ($lastChar) {
			case 'g':
				$value *= 1024;
				// no break
			case 'm':
				$value *= 1024;
				// no break
			case 'k':
				$value *= 1024;
		}
		
		return $value;
	}
	
	/**
	 * Formats a byte value into a php memory_limit string
	 *
	 * @param int $bytes
	 * @return string Formatted memory limit (e.g., '256M')
	 */
	public static function formatBytes(int $bytes): string
	{
		if ($bytes >= 1073741824) {
			return round($bytes / 1073741824, 2) . 'G';
		}
		elseif ($bytes >= 1048576) {
			return round($bytes / 1048576, 2) . 'M';
		}
		elseif ($bytes >= 1024) {
			return round($bytes / 1024, 2) . 'K';
		}
		
		return $bytes . 'B';
	}
	
	/**
	 * Sanitize a string for input/output. Generally set $escape_quotes to TRUE for output but FALSE for input
	 * @param string $str A string that may contain <script> tags
	 * @param bool|int $escape_quotes Convert quotes and >, < to HTML entities. Only do this on text output. Also accepts constants like: ENT_QUOTES
	 * @return string The sanitized string
	 */
	public static function removeScriptTags(string $str, bool|int $escape_quotes = false): string
	{
		// Loop over this until no more changes are made to catch nested tags.
		//TODO: Implement more robust solution
		$cleaned = $str;
		do {
			$str = $cleaned;
			$cleaned = preg_replace("/<[^>]*script[^>]*>/", "", $str);
			$cleaned = preg_replace("/<[^>]*svg[^>]*>/", "", $cleaned);
			$cleaned = preg_replace("/<[^>]*animate[^>]*>/", "", $cleaned);
			$cleaned = preg_replace("/<[^>]*source[^>]*>/", "", $cleaned);
		}
		while ($cleaned != $str);
		// Detect quotes and less than greater than which can escape out of the current html element
		if (!empty($escape_quotes)) {
			if ($escape_quotes === true) {
				$escape_quotes = ENT_COMPAT | ENT_HTML401 | ENT_QUOTES;
			}
			$cleaned = htmlspecialchars($cleaned, $escape_quotes);
		}
		return $cleaned;
	}
	
	
	/**
	 * Takes in an XML string and properly escapes any unescaped ampersands. Leaves proper ampersand entities alone.
	 * @param string $xml_string
	 * @return string
	 */
	public static function xml_ampersand_escape(string $xml_string): string
	{
		return preg_replace('/&(?!(?:apos|quot|[gl]t|amp);|#)/', '&amp;', $xml_string);
	}
	
	
	/**
	 * Takes in a truncated JSON string and completes it so it can be parsed. Obviously truncated data will be lost. Start of JSON string must be valid up to point of truncation.
	 * @param string $json
	 * @return string
	 */
	public static function untruncate_JSON(string $json): string
	{
		$ContextType = [ "TOP_LEVEL" => "topLevel", "STRING" => "string", "STRING_ESCAPED" => "stringEscaped", "STRING_UNICODE" => "stringUnicode", "NUMBER" => "number", "NUMBER_NEEDS_DIGIT" => "numberNeedsDigit", "NUMBER_NEEDS_EXPONENT" => "numberNeedsExponent", "TRUE" => "true", "FALSE" => "false", "NULL" => "null", "ARRAY_NEEDS_VALUE" => "arrayNeedsValue", "ARRAY_NEEDS_COMMA" => "arrayNeedsComma", "OBJECT_NEEDS_KEY" => "objectNeedsKey", "OBJECT_NEEDS_COLON" => "objectNeedsColon", "OBJECT_NEEDS_VALUE" => "objectNeedsValue", "OBJECT_NEEDS_COMMA" => "objectNeedsComma" ];
		$RespawnReason = [ "STRING_ESCAPE" => "stringEscape", "COLLECTION_ITEM" => "collectionItem" ];
		
		$contextStack = [$ContextType["TOP_LEVEL"]];
		$position = 0;
		$respawnPosition = null;
		$respawnStackLength = null;
		$respawnReason = null;
		
		$isWhitespace = function ($char) {
			return match ($char) {
				" ", "\r", "\n", "\t" => true,
				default => false,
			};
		};
		
		
		$replace = function ($context) use (&$contextStack) {
			return ($contextStack[count($contextStack) - 1] = $context);
		};
		$setRespawn = function ($reason) use (&$respawnReason, &$respawnStackLength, &$contextStack, &$respawnPosition, &$position) {
			if ($respawnPosition === null) {
				$respawnPosition = $position;
				$respawnStackLength = count($contextStack);
				$respawnReason = $reason;
			}
		};
		$clearRespawn = function ($reason) use (&$respawnReason, &$respawnStackLength, &$respawnPosition) {
			if ($reason === $respawnReason) {
				$respawnPosition = null;
				$respawnStackLength = null;
				$respawnReason = null;
			}
		};
		
		$dontConsumeCharacter = function () use (&$position) {
			return $position--;
		};
		$startAny = function ($char) use (&$ContextType, &$contextStack) {
			if ("0" <= $char && $char <= "9") {
				$contextStack[] = $ContextType["NUMBER"];
				return;
			}
			switch ($char) {
				case "\"":
					$contextStack[] = $ContextType["STRING"];
					return;
				case "-":
					$contextStack[] = $ContextType["NUMBER_NEEDS_DIGIT"];
					return;
				case "t":
					$contextStack[] = $ContextType["TRUE"];
					return;
				case "f":
					$contextStack[] = $ContextType["FALSE"];
					return;
				case "n":
					$contextStack[] = $ContextType["NULL"];
					return;
				case "[":
					$contextStack[] = $ContextType["ARRAY_NEEDS_VALUE"];
					return;
				case "{":
					$contextStack[] = $ContextType["OBJECT_NEEDS_KEY"];
					return;
			}
		};
		$length = 0;
		for ($length = mb_strlen($json); $position < $length; $position++) {
			$char = $json[$position];
			switch ($contextStack[array_key_last($contextStack)]) {
				case $ContextType["TOP_LEVEL"]:
					$startAny($char);
					break;
				case $ContextType["STRING"]:
					switch ($char) {
						case "\"":
							array_pop($contextStack);
							break;
						case "\\":
							$setRespawn($RespawnReason["STRING_ESCAPE"]);
							$contextStack[] = $ContextType["STRING_ESCAPED"];
							break;
					}
					break;
				case $ContextType["STRING_ESCAPED"]:
					// Disabled Unicode check b/c it is breaking parsing
					//	if ($char === "u") {
					//		$contextStack[] = $ContextType["STRING_UNICODE"];
					//	}
					//	else {
					$clearRespawn($RespawnReason["STRING_ESCAPE"]);
					array_pop($contextStack);
					//	}
					break;
				case $ContextType["STRING_UNICODE"]:
					if ($position - strrpos($json, "u", -$position) === 4) {
						$clearRespawn($RespawnReason["STRING_ESCAPE"]);
						array_pop($contextStack);
					}
					break;
				case $ContextType["NUMBER"]:
					if ($char === ".") {
						$replace($ContextType["NUMBER_NEEDS_DIGIT"]);
					}
					elseif ($char === "e" || $char === "E") {
						$replace($ContextType["NUMBER_NEEDS_EXPONENT"]);
					}
					elseif ($char < "0" || $char > "9") {
						$dontConsumeCharacter();
						array_pop($contextStack);
					}
					break;
				case $ContextType["NUMBER_NEEDS_DIGIT"]:
					$replace($ContextType["NUMBER"]);
					break;
				case $ContextType["NUMBER_NEEDS_EXPONENT"]:
					if ($char === "+" || $char === "-") {
						$replace($ContextType["NUMBER_NEEDS_DIGIT"]);
					}
					else {
						$replace($ContextType["NUMBER"]);
					}
					break;
				case $ContextType["TRUE"]:
				case $ContextType["FALSE"]:
				case $ContextType["NULL"]:
					if ($char < "a" || $char > "z") {
						$dontConsumeCharacter();
						array_pop($contextStack);
					}
					break;
				case $ContextType["ARRAY_NEEDS_VALUE"]:
					if ($char === "]") {
						array_pop($contextStack);
					}
					elseif (!$isWhitespace($char)) {
						$clearRespawn($RespawnReason["COLLECTION_ITEM"]);
						$replace($ContextType["ARRAY_NEEDS_COMMA"]);
						$startAny($char);
					}
					break;
				case $ContextType["ARRAY_NEEDS_COMMA"]:
					if ($char === "]") {
						array_pop($contextStack);
					}
					elseif ($char === ",") {
						$setRespawn($RespawnReason["COLLECTION_ITEM"]);
						$replace($ContextType["ARRAY_NEEDS_VALUE"]);
					}
					break;
				case $ContextType["OBJECT_NEEDS_KEY"]:
					if ($char === "}") {
						array_pop($contextStack);
					}
					elseif ($char === "\"") {
						$setRespawn($RespawnReason["COLLECTION_ITEM"]);
						$replace($ContextType["OBJECT_NEEDS_COLON"]);
						$contextStack[] = $ContextType["STRING"];
					}
					break;
				case $ContextType["OBJECT_NEEDS_COLON"]:
					if ($char === ":") {
						$replace($ContextType["OBJECT_NEEDS_VALUE"]);
					}
					break;
				case $ContextType["OBJECT_NEEDS_VALUE"]:
					if (!$isWhitespace($char)) {
						$clearRespawn($RespawnReason["COLLECTION_ITEM"]);
						$replace($ContextType["OBJECT_NEEDS_COMMA"]);
						$startAny($char);
					}
					break;
				case $ContextType["OBJECT_NEEDS_COMMA"]:
					if ($char === "}") {
						array_pop($contextStack);
					}
					elseif ($char === ",") {
						$setRespawn($RespawnReason["COLLECTION_ITEM"]);
						$replace($ContextType["OBJECT_NEEDS_KEY"]);
					}
					break;
			}
		}
		// Shorten array length
		if ($respawnStackLength !== null) {
			array_splice($contextStack, $respawnStackLength);
		}

		$result = $respawnPosition !== null ? mb_substr($json, 0, $respawnPosition) : $json;
		
		$finishWord = function ($word) use (&$json, &$result, &$length) {
			return $result . mb_substr($word, $length - strrpos($json, $word[0]));
		};
		for ($i = count($contextStack) - 1; $i >= 0; $i--) {
			switch ($contextStack[$i]) {
				case $ContextType["STRING"]:
					$result .= "\"";
					break;
				case $ContextType["NUMBER_NEEDS_DIGIT"]:
				case $ContextType["NUMBER_NEEDS_EXPONENT"]:
					$result .= "0";
					break;
				case $ContextType["TRUE"]:
					$finishWord("true");
					break;
				case $ContextType["FALSE"]:
					$finishWord("false");
					break;
				case $ContextType["NULL"]:
					$finishWord("null");
					break;
				case $ContextType["ARRAY_NEEDS_VALUE"]:
				case $ContextType["ARRAY_NEEDS_COMMA"]:
					$result .= "]";
					break;
				case $ContextType["OBJECT_NEEDS_KEY"]:
				case $ContextType["OBJECT_NEEDS_COLON"]:
				case $ContextType["OBJECT_NEEDS_VALUE"]:
				case $ContextType["OBJECT_NEEDS_COMMA"]:
					$result .= "}";
					break;
			}
		}
		return $result;
	}

}
