<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Parse Set-Cookie from AOT/CGI stdout (Status + headers before body — issue #1891).
 */
final class CgiCookieJar
{
    /** @var array<string, string> */
    private array $cookies = [];

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->cookies;
    }

    public function get(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function ingestCgiOutput(string $output): void
    {
        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            if (!preg_match('/^Set-Cookie:\s*(.+)$/i', $line, $m)) {
                continue;
            }
            $this->parseCookiePair(trim($m[1]));
        }
    }

    public function httpCookieHeader(): string
    {
        if ([] === $this->cookies) {
            return '';
        }
        $parts = [];
        foreach ($this->cookies as $name => $value) {
            $parts[] = rawurlencode($name).'='.rawurlencode($value);
        }

        return implode('; ', $parts);
    }

    private function parseCookiePair(string $pair): void
    {
        $segments = array_map('trim', explode(';', $pair));
        if ([] === $segments) {
            return;
        }
        $nv = explode('=', $segments[0], 2);
        if (2 !== count($nv)) {
            return;
        }
        $this->cookies[trim($nv[0])] = trim($nv[1]);
    }
}
