<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Map upstream PHPT web sections to CGI env vars (issue #102).
 *
 * GET/POST/COOKIE sections override the same keys from --ENV-- when both are present.
 */
final class PhptWebSections
{
    /**
     * @param array<string, string> $env
     * @param array<string, string> $sections
     */
    public static function applyToEnv(array &$env, array $sections): void
    {
        if (isset($sections['GET'])) {
            $env['QUERY_STRING'] = self::normalizeSection($sections['GET']);
        }
        if (isset($sections['POST'])) {
            $body = self::normalizeSection($sections['POST']);
            $env['REQUEST_BODY'] = $body;
            if ('' !== $body && !isset($env['REQUEST_METHOD'])) {
                $env['REQUEST_METHOD'] = 'POST';
            }
        }
        if (isset($sections['COOKIE'])) {
            $env['HTTP_COOKIE'] = self::normalizeSection($sections['COOKIE']);
        }
    }

    /**
     * Compile-time -q/-p flags for bin/compile.php (mirrors QUERY_STRING / REQUEST_BODY in ENV).
     *
     * @param array<string, string> $sections
     *
     * @return list<string>
     */
    public static function compileArgvFlags(array $sections): array
    {
        $argv = [];
        if (isset($sections['GET'])) {
            $qs = self::normalizeSection($sections['GET']);
            $argv[] = '-q';
            $argv[] = $qs;
        }
        if (isset($sections['POST'])) {
            $body = self::normalizeSection($sections['POST']);
            if ('' !== $body) {
                $argv[] = '-p';
                $argv[] = $body;
            }
        }

        return $argv;
    }

    /**
     * @param array<string, string> $sections
     *
     * @return list<string>
     */
    public static function envLinesFromSections(array $sections): array
    {
        $lines = [];
        if (isset($sections['GET'])) {
            $lines[] = 'QUERY_STRING='.self::normalizeSection($sections['GET']);
        }
        if (isset($sections['POST'])) {
            $body = self::normalizeSection($sections['POST']);
            $lines[] = 'REQUEST_BODY='.$body;
            if ('' !== $body) {
                $lines[] = 'REQUEST_METHOD=POST';
            }
        }
        if (isset($sections['COOKIE'])) {
            $lines[] = 'HTTP_COOKIE='.self::normalizeSection($sections['COOKIE']);
        }

        return $lines;
    }

    private static function normalizeSection(string $raw): string
    {
        return rtrim($raw, "\r\n");
    }
}
