<?php

declare(strict_types=1);

/**
 * Parse M3 compile-driver allowlist/denylist from lib/JIT.php (issues #1768, #1905).
 *
 * @return array{allow: list<string>, deny: list<string>}
 */
function bootstrap_m3_allowlist_from_jit(string $jitPath): array
{
    if (!is_readable($jitPath)) {
        return ['allow' => [], 'deny' => []];
    }
    $source = (string) file_get_contents($jitPath);

    $allow = [];
    if (preg_match(
        '/private function isM3CompileDriverRealLoweringName\(string \$lower\): bool\s*\{(.*?)^    \}/ms',
        $source,
        $fnMatch
    )) {
        $body = $fnMatch[1];
        if (preg_match_all("/str_ends_with\(\\\$lower,\s*'((?:\\\\.|[^'\\\\])*)'\)/", $body, $matches)) {
            foreach ($matches[1] as $raw) {
                $allow[] = stripcslashes($raw);
            }
        }
    }

    $deny = [];
    if (preg_match(
        '/private function m3CompileDriverSpineDenyNames\(\): array\s*\{\s*return \[(.*?)\];\s*\}/s',
        $source,
        $denyMatch
    )) {
        if (preg_match_all("/'((?:\\\\.|[^'\\\\])*)'/", $denyMatch[1], $denyStrings)) {
            foreach ($denyStrings[1] as $raw) {
                $deny[] = stripcslashes($raw);
            }
        }
    }

    $allow = bootstrap_m3_allowlist_unique_sorted($allow);
    $deny = bootstrap_m3_allowlist_unique_sorted($deny);

    return ['allow' => $allow, 'deny' => $deny];
}

/**
 * @param list<string> $names
 *
 * @return list<string>
 */
function bootstrap_m3_allowlist_unique_sorted(array $names): array
{
    $unique = array_values(array_unique($names));
    sort($unique, SORT_STRING);

    return $unique;
}

/**
 * @param array{allow: list<string>, deny: list<string>} $lists
 *
 * @return list<string>
 */
function bootstrap_m3_allowlist_snapshot_lines(array $lists): array
{
    $lines = ['# M3 compile-driver allowlist/denylist — regenerate: php script/bootstrap-m3-allowlist-snapshot.php --write'];
    foreach ($lists['allow'] as $name) {
        $lines[] = 'allow:'.$name;
    }
    foreach ($lists['deny'] as $name) {
        $lines[] = 'deny:'.$name;
    }

    return $lines;
}

/**
 * @return array{allow: list<string>, deny: list<string>}
 */
function bootstrap_m3_allowlist_read_snapshot(string $snapshotPath): array
{
    $allow = [];
    $deny = [];
    if (!is_readable($snapshotPath)) {
        return ['allow' => $allow, 'deny' => $deny];
    }
    foreach (file($snapshotPath, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ('' === $line || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'allow:')) {
            $allow[] = substr($line, 6);
            continue;
        }
        if (str_starts_with($line, 'deny:')) {
            $deny[] = substr($line, 5);
        }
    }

    return [
        'allow' => bootstrap_m3_allowlist_unique_sorted($allow),
        'deny' => bootstrap_m3_allowlist_unique_sorted($deny),
    ];
}
