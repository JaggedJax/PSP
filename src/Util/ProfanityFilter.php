<?php

namespace PSP\Util;

/**
 * Stars out a basic blacklist of words in user-submitted text (EG: reviews) before it is
 * saved. This is not a moderation system - just a simple first pass to keep obvious stuff out
 * of stored text.
 */
class ProfanityFilter
{
	/** @var string[] Words replaced with asterisks, case-insensitively, on a whole-word match. Keep list minimal */
	private const array BLACKLIST = [
		'cunt', 'fag', 'faggot',
		'fuck', 'fucker', 'fucking', 'motherfucker',
		'nigger', 'retard', 'whore',
	];

	/**
	 * Replace every blacklisted word in the given text with asterisks of the same length
	 * @param string $text
	 * @return string
	 */
	public static function censor(string $text): string
	{
		if ($text === '') {
			return $text;
		}

		$pattern = '/\b(' . implode('|', array_map(
			static fn (string $word) => preg_quote($word, '/'),
			self::BLACKLIST
		)) . ')\b/i';

		return preg_replace_callback(
			$pattern,
			static fn (array $match) => str_repeat('*', mb_strlen($match[0], 'UTF-8')),
			$text
		);
	}
}
