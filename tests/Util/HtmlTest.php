<?php

namespace PSP\Tests\Util;

use PSP\Util\Html;
use PHPUnit\Framework\TestCase;

/**
 * Covers Html::sanitize_html(), used for all HTML that is shown with |raw
 */
class HtmlTest extends TestCase
{
	public function testRemovesScriptsEventHandlersAndStyles(): void
	{
		$html = Html::sanitize_html('<p onclick="x()" style="color:red" class="ql-align-center">Hi</p><img src="a.png" onerror="alert(1)"><script>alert(1)</script>');

		$this->assertStringNotContainsString('onclick', $html);
		$this->assertStringNotContainsString('onerror', $html);
		$this->assertStringNotContainsString('style', $html);
		$this->assertStringNotContainsString('<script', $html);
		$this->assertStringContainsString('<p class="ql-align-center">Hi</p>', $html);
	}

	public function testRemovesUnsafeUrls(): void
	{
		$html = Html::sanitize_html('<a href="javascript:alert(1)">a</a><a href=" JaVa&#x09;script:alert(1)">b</a><img src="data:image/svg+xml;base64,AAAA">');

		$this->assertSame('<a>a</a><a>b</a><img>', $html);
	}

	public function testKeepsSafeContent(): void
	{
		$input = '<ol><li data-list="bullet">Caf\u{e9} &amp; more</li></ol><a href="https://example.com">x</a><a href="/products/1">y</a><img src="data:image/png;base64,AAAA" alt="z">';

		$this->assertSame($input, Html::sanitize_html($input));
	}

	public function testLinksThatOpenANewTabGetNoopener(): void
	{
		$this->assertSame(
			'<a href="https://example.com" target="_blank" rel="noopener noreferrer">x</a>',
			Html::sanitize_html('<a href="https://example.com" target="_blank">x</a>')
		);
	}

	public function testOutputIsStableWhenSanitizedAgain(): void
	{
		$once = Html::sanitize_html('<p onclick="x()">a <b>b</b></p><ul><li>c</li></ul>');

		$this->assertSame($once, Html::sanitize_html($once));
	}
}
