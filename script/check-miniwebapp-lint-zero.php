#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fail when examples/003-MiniWebApp has any unsupported lint nodes (issue #2078, #539).
 *
 * Usage:
 *   php script/check-miniwebapp-lint-zero.php
 */

$root = dirname(__DIR__);
$tree = $root.'/examples/003-MiniWebApp';

if (!is_dir($tree.'/public')) {
    fwrite(STDERR, "check-miniwebapp-lint-zero: missing {$tree}/public\n");
    exit(1);
}

$phpc = $root.'/phpc';
if (!is_executable($phpc) && is_file($root.'/bin/phpc.php')) {
    $phpc = PHP_BINARY.' '.$root.'/bin/phpc.php';
}

$stderrFile = tempnam(sys_get_temp_dir(), 'miniwebapp-lint-zero-');
$cmd = escapeshellcmd($phpc).' lint --all '.escapeshellarg($tree).' --json 2>'.escapeshellarg($stderrFile);
exec($cmd, $lines, $code);
$stdout = implode("\n", $lines);
$stderr = is_readable($stderrFile) ? (string) file_get_contents($stderrFile) : '';
@unlink($stderrFile);

if ($code !== 0) {
    fwrite(STDERR, "check-miniwebapp-lint-zero: phpc lint --all exited {$code}\n");
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
    }
    if ($stdout !== '') {
        fwrite(STDERR, $stdout."\n");
    }
    fwrite(STDERR, "check-miniwebapp-lint-zero: FAILED (fix unsupported syntax under examples/003-MiniWebApp — #2078)\n");
    exit(1);
}

$decoded = json_decode($stdout, true);
if (!is_array($decoded) || !isset($decoded['issues']) || !is_array($decoded['issues'])) {
    fwrite(STDERR, "check-miniwebapp-lint-zero: could not parse lint JSON\n");
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
    }
    if ($stdout !== '') {
        fwrite(STDERR, $stdout."\n");
    }
    exit(1);
}

$issues = $decoded['issues'];
$count = count($issues);
if ($count > 0) {
    fwrite(STDERR, "check-miniwebapp-lint-zero: {$count} unsupported issue(s) under examples/003-MiniWebApp\n");
    $shown = 0;
    foreach ($issues as $issue) {
        if ($shown >= 16) {
            fwrite(STDERR, '  … and '.($count - $shown)." more\n");
            break;
        }
        $file = $issue['file'] ?? '?';
        $line = $issue['line'] ?? 0;
        $kind = $issue['kind'] ?? '?';
        $url = $issue['issue_url'] ?? '';
        if ($url === '' && !empty($issue['issue'])) {
            $url = 'https://github.com/PurHur/php-compiler/issues/'.$issue['issue'];
        }
        $suffix = $url !== '' ? " -> {$url}" : '';
        fwrite(STDERR, "  {$file}:{$line}: {$kind}{$suffix}\n");
        ++$shown;
    }
    fwrite(STDERR, "check-miniwebapp-lint-zero: FAILED (#2078, #539; see src/Router.php blockers)\n");
    exit(1);
}

fwrite(STDOUT, "check-miniwebapp-lint-zero: OK (zero unsupported nodes)\n");
exit(0);
