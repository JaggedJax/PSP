<?php

namespace PSP\Util;

class Html
{
	/** Tags kept by sanitize_html() */
	public const array ALLOWED_TAGS = ['<a>','<b>','<br>','<div>','<em>','<h1>','<h2>','<h3>','<h4>','<img>','<i>','<li>','<ol>','<p>','<span>','<strong>','<table>','<th>','<tr>','<td>','<u>','<ul>'];

	/**
	 * HTML attributes kept by sanitize_html(), per tag. '*' applies to every allowed tag. All other attributes are removed
	 */
	public const array ALLOWED_ATTRIBUTES = [
		'*' => ['class'],
		'a' => ['href', 'target', 'rel', 'title'],
		'img' => ['src', 'alt', 'title', 'width', 'height'],
		'li' => ['data-list'],
		'td' => ['colspan', 'rowspan'],
		'th' => ['colspan', 'rowspan'],
	];

	/**
	 * Make HTML safe to show with |raw. Keeps only ALLOWED_TAGS and ALLOWED_ATTRIBUTES,
	 * which removes event handlers (eg. onerror) and styles, and removes links and images that are not safe URLs
	 * @param string|null $html
	 * @return string
	 */
	public static function sanitize_html(?string $html): string
	{
		$html = strip_tags((string)$html, self::ALLOWED_TAGS);
		if (trim($html) === '' || !str_contains($html, '<')) {
			return $html;
		}

		$dom = new \DOMDocument();
		$previous_errors = libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();
		libxml_use_internal_errors($previous_errors);

		$root = $dom->getElementsByTagName('div')->item(0);
		if ($root === null) {
			return '';
		}

		foreach ((new \DOMXPath($dom))->query('.//*', $root) as $element) {
			/** @var \DOMElement $element */
			$tag = strtolower($element->tagName);
			$allowed = array_merge(self::ALLOWED_ATTRIBUTES['*'], self::ALLOWED_ATTRIBUTES[$tag] ?? []);

			foreach (iterator_to_array($element->attributes) as $attribute) {
				$name = strtolower($attribute->name);
				if (!in_array($name, $allowed, true)
					|| ($name === 'href' && !self::is_safe_url($attribute->value, false))
					|| ($name === 'src' && !self::is_safe_url($attribute->value, true))
				) {
					$element->removeAttribute($attribute->name);
				}
			}

			// A link that opens a new tab must not get access to this page
			if ($tag === 'a' && strtolower($element->getAttribute('target')) === '_blank') {
				$element->setAttribute('rel', 'noopener noreferrer');
			}
		}

		$clean = '';
		foreach ($root->childNodes as $child) {
			$clean .= $dom->saveHTML($child);
		}
		return $clean;
	}

	/**
	 * Is this URL safe for a link or image? Allows http(s), relative URLs, mailto/tel links, and raster data images
	 * @param string $url
	 * @param bool $is_image TRUE for an image src, which also allows data:image URLs but not mailto/tel
	 * @return bool
	 */
	private static function is_safe_url(string $url, bool $is_image): bool
	{
		// Browsers ignore whitespace and control characters in a scheme, eg. "java\tscript:"
		$url = preg_replace('/[\x00-\x20]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
		if ($url === '' || !preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $matches)) {
			return true; // Relative URL
		}

		$scheme = strtolower($matches[1]);
		if ($is_image) {
			return in_array($scheme, ['http', 'https'], true)
				|| (bool)preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $url);
		}
		return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
	}
}
