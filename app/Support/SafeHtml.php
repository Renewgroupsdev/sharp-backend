<?php

namespace App\Support;

/**
 * Cleans the rich text staff type into a profile (biography): keeps basic formatting (paragraphs, headings, bold, italic,
 * underline, lists, quotes, line breaks, links) and drops everything else, including every attribute except a link's
 * http(s) / mailto address. Plain text passes through. An editor with nothing in it becomes null.
 */
final class SafeHtml
{
    private const ALLOWED = '<p><br><h2><h3><strong><b><em><i><u><ul><ol><li><blockquote><a>';

    public static function clean(?string $html): ?string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return null;
        }

        $html = strip_tags($html, self::ALLOWED);
        $html = preg_replace_callback('/<(\/?)([a-z0-9]+)([^>]*)>/i', function (array $m): string {
            $tag = strtolower($m[2]);
            if ($m[1] === '/') {
                return "</{$tag}>";
            }
            if ($tag === 'a' && preg_match('/href\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $m[3], $h)) {
                $href = html_entity_decode($h[1] !== '' ? $h[1] : ($h[2] ?? ''));
                if (preg_match('~^(https?://|mailto:)~i', $href)) {
                    return '<a href="'.htmlspecialchars($href, ENT_QUOTES).'" target="_blank" rel="noopener noreferrer">';
                }

                return '<a>';
            }

            return "<{$tag}>";
        }, $html) ?? '';

        return trim(strip_tags($html)) === '' ? null : $html;
    }
}
