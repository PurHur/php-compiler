#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Single source of truth for M3 compile-driver allow/deny lists (issues #1768, #1905).
 *
 * Parses lib/JIT.php:
 *   - isM3CompileDriverRealLoweringName() str_ends_with suffixes
 *   - m3CompileDriverSpineDenyNames() return array
 *
 * Usage:
 *   php script/bootstrap-m3-allowlist-snapshot.php          # human summary on stdout
 *   php script/bootstrap-m3-allowlist-snapshot.php --write  # refresh script/m3-allowlist-snapshot.txt
 */

$root = dirname(__DIR__);

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $write = in_array('--write', $argv, true);
    $lines = m3_allowlist_snapshot_lines($root);
    if ($write) {
        $path = $root.'/script/m3-allowlist-snapshot.txt';
        file_put_contents($path, implode("\n", $lines)."\n");
        fwrite(STDOUT, "bootstrap-m3-allowlist-snapshot: wrote {$path} (".count($lines)." lines)\n");
        exit(0);
    }
    $allow = 0;
    $deny = 0;
    foreach ($lines as $line) {
        if (str_starts_with($line, 'allow:')) {
            ++$allow;
        } elseif (str_starts_with($line, 'deny:')) {
            ++$deny;
        }
    }
    fwrite(STDOUT, "bootstrap-m3-allowlist-snapshot: allow={$allow} deny={$deny}\n");
    exit(0);
}

/**
 * @return list<string> sorted snapshot lines (allow:… / deny:…)
 */
function m3_allowlist_snapshot_lines(string $root): array
{
    $jitPath = $root.'/lib/JIT.php';
    if (!is_readable($jitPath)) {
        return [];
    }
    $jitSource = (string) file_get_contents($jitPath);
    $allow = m3_parse_real_lowering_suffixes($jitSource);
    $deny = m3_parse_spine_deny_fragments($jitSource);
    $lines = [];
    foreach ($allow as $suffix) {
        $lines[] = 'allow:'.$suffix;
    }
    foreach ($deny as $fragment) {
        $lines[] = 'deny:'.$fragment;
    }
    sort($lines, SORT_STRING);

    return $lines;
}

/**
 * @return list<string> lowercase str_ends_with suffixes (deduped, source order preserved before sort in snapshot)
 */
function m3_parse_real_lowering_suffixes(string $jitSource): array
{
    $start = strpos($jitSource, 'private function isM3CompileDriverRealLoweringName');
    $end = strpos($jitSource, 'private function m3CompileDriverSpineDenyNames');
    if (false === $start || false === $end || $end <= $start) {
        return [];
    }
    $body = substr($jitSource, $start, $end - $start);
    if (!preg_match_all("/str_ends_with\(\\\$lower,\s*'([^']+)'\)/", $body, $matches)) {
        return [];
    }
    $seen = [];
    $out = [];
    foreach ($matches[1] as $suffix) {
        $key = strtolower($suffix);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $key;
    }

    return $out;
}

/**
 * @return list<string> deny-list fragments from m3CompileDriverSpineDenyNames()
 */
function m3_parse_spine_deny_fragments(string $jitSource): array
{
    if (!preg_match(
        '/private function m3CompileDriverSpineDenyNames\(\): array\s*\{\s*return \[(.*?)\];/s',
        $jitSource,
        $match
    )) {
        return [];
    }
    if (!preg_match_all("/'((?:\\\\'|[^'])*)'/", $match[1], $stringMatches)) {
        return [];
    }
    $out = [];
    foreach ($stringMatches[1] as $raw) {
        $out[] = strtolower(stripslashes($raw));
    }

    return $out;
}
