#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Lint PHP sources for constructs the static compiler cannot lower yet.
 *
 * Usage:
 *   bin/lint.php [-r 'code'] [--json] <file.php>
 *   bin/lint.php --project <entry.php> [--json]
 *   bin/lint.php --all <dir-or-file> [--json]
 *   phpc lint ...
 */

use PHPCompiler\Lint\Issue;
use PHPCompiler\Lint\Linter;

require __DIR__.'/../src/tokenizer-compat.php';
require __DIR__.'/../src/yay-php8-compat.php';
require __DIR__.'/../vendor/autoload.php';

$json = false;
$code = null;
$filename = null;
$mode = 'file';
$args = array_slice($argv, 1);

while ([] !== $args) {
    $arg = array_shift($args);
    if ('--json' === $arg) {
        $json = true;
        continue;
    }
    if ('--all' === $arg) {
        $mode = 'all';
        continue;
    }
    if ('--project' === $arg) {
        $mode = 'project';
        continue;
    }
    if ('-r' === $arg) {
        if ([] === $args) {
            fwrite(STDERR, "Option -r requires code argument\n");
            exit(1);
        }
        $snippet = array_shift($args);
        $code = str_starts_with(ltrim($snippet), '<?') ? $snippet : '<?php '.$snippet;
        $filename = 'Command line code';
        $mode = 'file';
        continue;
    }
    if (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
    if (null !== $filename) {
        fwrite(STDERR, "Extra argument: {$arg}\n");
        exit(1);
    }
    if ('file' === $mode && !is_file($arg) && !is_dir($arg)) {
        fwrite(STDERR, "Could not open file {$arg}\n");
        exit(1);
    }
    if ('all' === $mode && !is_file($arg) && !is_dir($arg)) {
        fwrite(STDERR, "Not a file or directory: {$arg}\n");
        exit(1);
    }
    if ('project' === $mode && !is_file($arg)) {
        fwrite(STDERR, "Could not open file {$arg}\n");
        exit(1);
    }
    $filename = $arg;
    if ('file' === $mode) {
        $code = is_file($arg) ? (string) file_get_contents($arg) : null;
    }
}

if (null === $filename) {
    fwrite(STDERR, "Usage: lint.php [-r 'code'] [--json] <file.php>\n");
    fwrite(STDERR, "       lint.php --project <entry.php> [--json]\n");
    fwrite(STDERR, "       lint.php --all <dir-or-file> [--json]\n");
    exit(1);
}

$linter = new Linter();

try {
    if ('all' === $mode) {
        $issues = $linter->lintAll($filename);
    } elseif ('project' === $mode) {
        $issues = $linter->lintProject($filename);
    } elseif (null !== $code) {
        $issues = $linter->lintSource($code, $filename);
    } else {
        $issues = $linter->lintFile($filename);
    }
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}

foreach ($linter->consumeDynamicIncludeWarnings() as $warning) {
    fwrite(STDERR, "warning: {$warning}\n");
}

if ($json) {
    $payload = array_map(static fn (Issue $i) => $i->toArray(), $issues);
    fwrite(STDOUT, json_encode(['issues' => $payload], JSON_PRETTY_PRINT)."\n");
} else {
    foreach ($issues as $issue) {
        fwrite(STDOUT, $issue->formatHuman()."\n");
    }
}

exit([] === $issues ? 0 : 1);
