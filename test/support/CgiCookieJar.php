<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Parse Set-Cookie lines from AOT/CGI stdout for multi-request smokes (#1891).
 */
final class CgiCookieJar
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function absorbFromCgiOutput(string $output): void
    {
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (!str_starts_with($line, 'Set-Cookie: ')) {
                continue;
            }
            $this->parseSetCookieLine(substr($line, 12));
        }
    }

    public function httpCookieHeader(): string
    {
        $pairs = [];
        foreach ($this->cookies as $name => $value) {
            $pairs[] = $name.'='.$value;
        }

        return implode('; ', $pairs);
    }

    public function hasCookie(string $name): bool
    {
        return isset($this->cookies[$name]);
    }

    public function getCookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    private function parseSetCookieLine(string $line): void
    {
        $parts = explode(';', $line);
        $pair = trim($parts[0]);
        if (!str_contains($pair, '=')) {
            return;
        }
        [$name, $value] = explode('=', $pair, 2);
        $this->cookies[trim($name)] = trim($value);
    }
}
