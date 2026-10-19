<?php

namespace App\Support;

/**
 * Sanitises untrusted (Halo ticket / email) HTML for display.
 *
 * Uses symfony/html-sanitizer with a strict allow-list: no scripts, styles,
 * event handlers, forms or iframes; only http(s)/mailto links (forced
 * rel=noopener noreferrer, target=_blank) and https images. If the library is
 * not installed the fallback renders the content as escaped plain text, which
 * is always safe.
 */
class HtmlSanitizer
{
    private const ELEMENTS = [
        'p', 'br', 'strong', 'em', 'b', 'i', 'u', 'ul', 'ol', 'li', 'blockquote',
        'code', 'pre', 'span', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr',
    ];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        if (! class_exists(\Symfony\Component\HtmlSanitizer\HtmlSanitizer::class)) {
            return nl2br(e(trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        }

        $config = new \Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig();

        foreach (self::ELEMENTS as $element) {
            $config = $config->allowElement($element);
        }

        $config = $config
            ->allowElement('a', ['href'])
            ->allowElement('img', ['src', 'alt'])
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['https'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank')
            ->withMaxInputLength(500000);

        return (new \Symfony\Component\HtmlSanitizer\HtmlSanitizer($config))->sanitize($html);
    }
}
