#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard docs/bootstrap-m5-fast-path.md Step 2 against m3-allowlist-snapshot.txt (issue #1984).
 *
 * Machine-readable SSOT: script/m3-allowlist-snapshot.txt (JIT allow/deny via #1905).
 * Doc table must mention every snapshot symbol with matching allow/deny intent.
 *
 * Usage:
 *   php script/check-bootstrap-m5-doc-sync.php
 */

require __DIR__.'/bootstrap-m3-allowlist.php';

$root = dirname(__DIR__);
$docPath = $root.'/docs/bootstrap-m5-fast-path.md';
$snapshotPath = $root.'/script/m3-allowlist-snapshot.txt';

$errors = [];

if (!is_readable($docPath)) {
    $errors[] = 'missing docs/bootstrap-m5-fast-path.md';
}

if (!is_readable($snapshotPath)) {
    $errors[] = 'missing script/m3-allowlist-snapshot.txt';
}

if ([] !== $errors) {
    bootstrap_m5_doc_sync_fail($errors);
}

$doc = (string) file_get_contents($docPath);
$snapshot = bootstrap_m5_effective_allowlist(bootstrap_m3_allowlist_read_snapshot($snapshotPath));
$docSymbols = bootstrap_m5_doc_parse_symbol_status($doc);

/** @var array<string, true> $docAllow */
$docAllow = [];
/** @var array<string, true> $docDeny */
$docDeny = [];
foreach ($docSymbols as $key => $status) {
    if ('allow' === $status) {
        $docAllow[$key] = true;
    } else {
        $docDeny[$key] = true;
    }
}

foreach ($snapshot['allow'] as $name) {
    $key = bootstrap_m5_canonical_key($name);
    if (!isset($docAllow[$key]) && !isset($docDeny[$key])) {
        $errors[] = "snapshot allow {$name}: missing row in bootstrap-m5-fast-path.md Step 2 / LLVM deny table";
        continue;
    }
    if (isset($docDeny[$key])) {
        $errors[] = "snapshot allow {$name}: doc marks deny/native-blocked but snapshot is allow";
    }
}

foreach ($snapshot['deny'] as $name) {
    $key = bootstrap_m5_canonical_key($name);
    if (!isset($docAllow[$key]) && !isset($docDeny[$key])) {
        $errors[] = "snapshot deny {$name}: missing row in bootstrap-m5-fast-path.md Step 2 / LLVM deny table";
        continue;
    }
    if (isset($docAllow[$key])) {
        $errors[] = "snapshot deny {$name}: doc claims allowlist/native but snapshot is deny";
    }
}

if ([] !== $errors) {
    bootstrap_m5_doc_sync_fail($errors);
}

fwrite(
    STDOUT,
    'check-bootstrap-m5-doc-sync: OK (allow '.count($snapshot['allow']).', deny '.count($snapshot['deny'])." vs doc)\n"
);
exit(0);

/**
 * Doc-only symbols (AOT smoke fixtures) not mirrored in m3-allowlist-snapshot.txt.
 *
 * @return array<string, true>
 */
function bootstrap_m5_doc_ignore_keys(): array
{
    $ignored = [
        'runtime_ctor_smoke',
        'runtime_parse_compile_smoke',
    ];
    $map = [];
    foreach ($ignored as $symbol) {
        $map[bootstrap_m5_canonical_key($symbol)] = true;
    }

    return $map;
}

/**
 * @return array<string, 'allow'|'deny'>
 */
function bootstrap_m5_doc_parse_symbol_status(string $doc): array
{
    $ignore = bootstrap_m5_doc_ignore_keys();
    $symbols = [];

    $sections = [
        bootstrap_m5_doc_extract_section($doc, '## Step 2', '### Known LLVM'),
        bootstrap_m5_doc_extract_section($doc, '### Known LLVM 9 link crashers', '## Env flags'),
    ];

    foreach ($sections as $section) {
        foreach (explode("\n", $section) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, '|') || str_contains($line, '---')) {
                continue;
            }
            $cols = array_map('trim', explode('|', trim($line, '|')));
            if (count($cols) < 2) {
                continue;
            }
            $symbolCol = $cols[0];
            $statusCol = $cols[1];
            if (str_contains(strtolower($symbolCol), 'allowlist') || str_contains(strtolower($symbolCol), 'symbol')) {
                continue;
            }

            $status = bootstrap_m5_doc_classify_status($statusCol);
            preg_match_all('/`([^`]+)`/', $symbolCol, $matches);
            $rawSymbols = $matches[1];
            if ([] === $rawSymbols && preg_match('/[A-Za-z_][\w\\\\:]*::[A-Za-z_]\w*/', $symbolCol, $bare)) {
                $rawSymbols = [$bare[0]];
            }
            foreach ($rawSymbols as $rawSymbol) {
                foreach (bootstrap_m5_doc_split_symbols($rawSymbol) as $fragment) {
                    $key = bootstrap_m5_canonical_key($fragment);
                    if (isset($ignore[$key])) {
                        continue;
                    }
                    $symbols[$key] = $status;
                }
            }
        }
    }

    return $symbols;
}

/**
 * @return list<string>
 */
function bootstrap_m5_doc_split_symbols(string $raw): array
{
    $parts = preg_split('/\s*\/\s*/', $raw) ?: [];

    return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => '' !== $s));
}

function bootstrap_m5_doc_classify_status(string $statusCol): string
{
    $lower = strtolower($statusCol);
    if (preg_match('/\boff deny list\b/', $lower)) {
        return 'allow';
    }
    if (preg_match('/\b(on deny list|deny-listed|deny list|deny while|llvm 9 link crash|link crash|stubbed|hot-path skip)\b/', $lower)) {
        return 'deny';
    }

    return 'allow';
}

function bootstrap_m5_doc_extract_section(string $doc, string $start, string $end): string
{
    $startPos = strpos($doc, $start);
    if (false === $startPos) {
        return '';
    }
    $endPos = strpos($doc, $end, $startPos + strlen($start));
    if (false === $endPos) {
        return substr($doc, $startPos);
    }

    return substr($doc, $startPos, $endPos - $startPos);
}

function bootstrap_m5_canonical_key(string $name): string
{
    $key = strtolower(ltrim($name, '\\'));
    $key = str_replace('`', '', $key);

    if (str_contains($key, '::')) {
        $parts = explode('::', $key, 2);
        $class = $parts[0];
        $method = $parts[1] ?? '';
        if ('block' === $class && 'slotindexforvariablename' === $method) {
            return 'slotindexforvariablename';
        }
        if ('runtime' === $class && '' !== $method) {
            return 'runtime\\'.$method;
        }
        if ('bootstrapaot' === $class) {
            return 'bootstrapaot\\'.$method;
        }

        return $class.'\\'.$method;
    }

    if (str_contains($key, 'helloworld_compile_smoke') || str_contains($key, 'compile_smoke_m3_emit')) {
        $leaf = basename(str_replace('\\', '/', $key));

        return 'bootstrapaot\\'.$leaf;
    }

    if (!str_contains($key, '\\') && !str_contains($key, '::')) {
        if ('slotindexforvariablename' === $key) {
            return $key;
        }

        return 'runtime\\'.$key;
    }

    return str_replace('::', '\\', $key);
}

/**
 * Deny wins when JIT lists a symbol in both allow and deny (e.g. loadJitContext checked first in JIT.php).
 *
 * @param array{allow: list<string>, deny: list<string>} $snapshot
 *
 * @return array{allow: list<string>, deny: list<string>}
 */
function bootstrap_m5_effective_allowlist(array $snapshot): array
{
    $denyKeys = [];
    foreach ($snapshot['deny'] as $name) {
        $denyKeys[bootstrap_m5_canonical_key($name)] = true;
    }

    $allow = [];
    foreach ($snapshot['allow'] as $name) {
        if (!isset($denyKeys[bootstrap_m5_canonical_key($name)])) {
            $allow[] = $name;
        }
    }

    return [
        'allow' => bootstrap_m3_allowlist_unique_sorted($allow),
        'deny' => $snapshot['deny'],
    ];
}

/**
 * @param list<string> $errors
 */
function bootstrap_m5_doc_sync_fail(array $errors): void
{
    foreach ($errors as $err) {
        fwrite(STDERR, "check-bootstrap-m5-doc-sync: {$err}\n");
    }
    fwrite(STDERR, "check-bootstrap-m5-doc-sync: FAILED — sync docs/bootstrap-m5-fast-path.md with script/m3-allowlist-snapshot.txt (issue #1984).\n");
    exit(1);
}
