<?php

namespace App\Support\Https;

/** Makes subprocess output safe to store/show: no key material, no credentials, bounded size. */
final class LogSanitizer
{
    public static function clean(string $text, int $maxBytes = 6000): string
    {
        $text = preg_replace('/-----BEGIN [A-Z ]*(PRIVATE KEY|CERTIFICATE)-----.*?-----END [A-Z ]*(PRIVATE KEY|CERTIFICATE)-----/s', '[PEM REDACTED]', $text) ?? '';
        $text = preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*/s', '[PEM REDACTED]', $text) ?? '';
        $text = preg_replace('/(?i)\b([a-z_]*(?:password|passwd|secret|token|api[_-]?key|apikey|authorization)[a-z_]*)\s*[=:]\s*\S+/', '$1=[REDACTED]', $text) ?? '';
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';
        $text = trim($text);

        return strlen($text) > $maxBytes ? '...' . substr($text, -$maxBytes) : $text;
    }
}
