<?php

declare(strict_types=1);

/**
 * Shared parsers for stdlib JIT deferred drift guard (#2465).
 */

require_once __DIR__.'/stdlib-jit-deferred-lib.php';

/**
 * @return list<string>
 */
function stdlib_jit_deferred_parse_audit_deferred(string $auditText): array
{
    if (!preg_match('/## Deferred \\(VM-only\\)\s*\n+(.*?)(?:\n## |\z)/s', $auditText, $section)) {
        return [];
    }
    $body = $section[1];
    if (preg_match('/_None — all JIT/', $body)) {
        return [];
    }
    if (!preg_match_all('/^- `([^`]+)`/m', $body, $matches)) {
        return [];
    }

    return array_values($matches[1]);
}

function stdlib_jit_deferred_parse_audit_metric_count(string $auditText, string $label): ?int
{
    $pattern = '/\| '.preg_quote($label, '/').' \| (\d+) \|/';
    if (!preg_match($pattern, $auditText, $m)) {
        return null;
    }

    return (int) $m[1];
}

/**
 * @return array{jit_yes: bool, notes: string}|null
 */
function stdlib_jit_deferred_parse_capabilities_row(string $text, string $builtin): ?array
{
    $pattern = '/\| `'.preg_quote($builtin, '/').'` \| yes \| (yes|no) \|/';
    if (!preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $lineStart = strrpos(substr($text, 0, $m[0][1]), "\n");
    $lineStart = false === $lineStart ? 0 : $lineStart + 1;
    $lineEnd = strpos($text, "\n", $m[0][1]);
    $line = false === $lineEnd
        ? substr($text, $lineStart)
        : substr($text, $lineStart, $lineEnd - $lineStart);

    return [
        'jit_yes' => 'yes' === $m[1][0],
        'notes' => $line,
    ];
}

function stdlib_jit_deferred_capabilities_notes_ok(string $notes, int $issue): bool
{
    if (preg_match('/\(#'.$issue.'\)/', $notes)) {
        return true;
    }
    if (preg_match('/\bJIT deferred\b/i', $notes) || preg_match('/\bcompile-time JIT deferred\b/i', $notes)) {
        return true;
    }

    return false;
}
