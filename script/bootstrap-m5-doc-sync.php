<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap-m3-allowlist.php';

/**
 * Parse M3 allow/deny symbols from docs/bootstrap-m5-fast-path.md (issue #1984).
 *
 * SSOT for machine-readable lists: script/m3-allowlist-snapshot.txt (#1905).
 * Doc sections Step 1 → Step 2 → deny table; later sections override earlier keys.
 *
 * @return array{allow: list<string>, deny: list<string>}
 */
function bootstrap_m5_doc_parse_allow_deny(string $docPath): array
{
    if (!is_readable($docPath)) {
        return ['allow' => [], 'deny' => []];
    }

    $text = (string) file_get_contents($docPath);
    $statusByKey = [];

    $step1 = extract_markdown_section($text, '## Step 1', '## Step 2');
    parse_m5_doc_table_section($step1, 'allow', $statusByKey);

    $step2 = extract_markdown_section($text, '## Step 2', '### Known LLVM');
    parse_m5_doc_table_section($step2, 'allow', $statusByKey);

    $denySection = extract_markdown_section($text, '### Known LLVM 9 link crashers', '**Next:**');
    parse_m5_doc_table_section($denySection, 'deny', $statusByKey);

    $allow = [];
    $deny = [];
    foreach ($statusByKey as $key => $status) {
        if ('deny' === $status) {
            $deny[] = $key;
        } else {
            $allow[] = $key;
        }
    }

    return [
        'allow' => bootstrap_m3_allowlist_unique_sorted($allow),
        'deny' => bootstrap_m3_allowlist_unique_sorted($deny),
    ];
}

/**
 * Keys documented as bootstrap AOT scripts only (not JIT allowlist SSOT).
 *
 * @return list<string>
 */
function bootstrap_m5_doc_fixture_only_keys(): array
{
    return [
        '\\bootstrapaot\\runtime_ctor_smoke',
        '\\bootstrapaot\\runtime_parse_compile_smoke',
    ];
}

/**
 * Map a doc backtick symbol to snapshot/JIT key form.
 */
function bootstrap_m5_doc_symbol_to_key(string $symbol): string
{
    $symbol = trim($symbol);
    if (str_contains($symbol, '::')) {
        [$class, $method] = explode('::', $symbol, 2);
        $classLower = strtolower($class);
        $methodLower = strtolower($method);
        if ('block' === $classLower) {
            return $methodLower;
        }
        if ('runtime' === $classLower) {
            return '\\runtime::'.$methodLower;
        }
        if ('compiler' === $classLower) {
            return '\\compiler::'.$methodLower;
        }

        return '\\'.strtolower(str_replace('::', '\\', $symbol));
    }

    // Global allowlist entries from lib/JIT.php (not bootstrap-aot fixtures).
    if (str_starts_with(strtolower($symbol), 'php_compiler_')) {
        return '\\'.strtolower($symbol);
    }

    return '\\bootstrapaot\\'.strtolower($symbol);
}

/**
 * @param array<string, string> $statusByKey
 */
function parse_m5_doc_table_section(string $section, string $defaultStatus, array &$statusByKey): void
{
    foreach (explode("\n", $section) as $line) {
        if (!preg_match('/^\| (.+?) \| (.+) \|$/', $line, $row)) {
            continue;
        }
        if (!str_contains($row[1], '`')) {
            continue;
        }
        $symbols = bootstrap_m5_doc_symbols_from_cell($row[1]);
        $notes = $row[2];
        $status = classify_m5_doc_row_status($notes, $defaultStatus);
        foreach ($symbols as $symbol) {
            $key = bootstrap_m5_doc_symbol_to_key($symbol);
            if (in_array($key, bootstrap_m5_doc_fixture_only_keys(), true)) {
                continue;
            }
            $statusByKey[$key] = $status;
        }
    }
}

/**
 * @return list<string>
 */
function bootstrap_m5_doc_symbols_from_cell(string $cell): array
{
    if (!preg_match_all('/`([^`]+)`/', $cell, $matches)) {
        return [];
    }

    $symbols = [];
    $anchor = $matches[1][0];
    foreach ($matches[1] as $raw) {
        $symbols[] = bootstrap_m5_doc_qualify_symbol($anchor, $raw);
    }

    return array_values(array_unique($symbols));
}

function bootstrap_m5_doc_qualify_symbol(string $anchor, string $raw): string
{
    if (str_contains($raw, '::')) {
        return $raw;
    }
    if (str_contains($anchor, '::')) {
        [$class] = explode('::', $anchor, 2);

        return $class.'::'.$raw;
    }

    return $raw;
}

function classify_m5_doc_row_status(string $notes, string $defaultStatus): string
{
    $lower = strtolower($notes);
    if ('deny' === $defaultStatus) {
        return 'deny';
    }
    if (preg_match('/\boff deny list\b/', $lower)) {
        return 'allow';
    }
    if (preg_match('/\bdeny-listed|on deny list|stay on deny\b/', $lower)) {
        return 'deny';
    }

    return 'allow';
}

function extract_markdown_section(string $text, string $start, string $end): string
{
    $startPos = strpos($text, $start);
    if (false === $startPos) {
        return '';
    }
    $endPos = strpos($text, $end, $startPos + strlen($start));
    if (false === $endPos) {
        return substr($text, $startPos);
    }

    return substr($text, $startPos, $endPos - $startPos);
}
