<?php

namespace PSP\Tests\Util;

use PSP\Util\ProfanityFilter;
use PHPUnit\Framework\TestCase;

class ProfanityFilterTest extends TestCase
{
	public function testBlacklistedWordIsStarredOut(): void
	{
		$this->assertSame('This is ****.', ProfanityFilter::censor('This is fuck.'));
	}

	public function testMatchingIsCaseInsensitive(): void
	{
		$this->assertSame('****!', ProfanityFilter::censor('FUCK!'));
	}

	public function testOnlyWholeWordsAreMatched(): void
	{
		// "Scunthorpe" contains a blacklisted word but must not be censored
		$this->assertSame('Shipped from Scunthorpe.', ProfanityFilter::censor('Shipped from Scunthorpe.'));
	}

	public function testCleanTextIsUnchanged(): void
	{
		$text = 'Great product, works exactly as described.';
		$this->assertSame($text, ProfanityFilter::censor($text));
	}

	public function testEmptyStringIsUnchanged(): void
	{
		$this->assertSame('', ProfanityFilter::censor(''));
	}

	public function testMultipleBlacklistedWordsAreAllStarredOut(): void
	{
		$this->assertSame('This is **** *****.', ProfanityFilter::censor('This is fuck whore.'));
	}
}
