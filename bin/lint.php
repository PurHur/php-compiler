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
 *   bin/lint.php --bootstrap-inventory [--check] [--json]
 *   phpc lint ...
 */

use PHPCompiler\Lint\Issue;
use PHPCompiler\Lint\Linter;

require __DIR__.'/../src/tokenizer-compat.php';
require __DIR__.'/../src/yay-php8-compat.php';
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../script/bootstrap-lib.php';

$json = false;
$check = false;
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
    if ('--check' === $arg) {
        $check = true;
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
    if ('--bootstrap-inventory' === $arg) {
        $mode = 'bootstrap-inventory';
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

if ('bootstrap-inventory' !== $mode && null === $filename) {
    fwrite(STDERR, "Usage: lint.php [-r 'code'] [--json] <file.php>\n");
    fwrite(STDERR, "       lint.php --project <entry.php> [--json]\n");
    fwrite(STDERR, "       lint.php --all <dir-or-file> [--json]\n");
    fwrite(STDERR, "       lint.php --bootstrap-inventory [--check] [--json]\n");
    exit(1);
}

$linter = new Linter();

try {
    if ('bootstrap-inventory' === $mode) {
        $repoRoot = realpath(__DIR__.'/..') ?: __DIR__.'/..';
        $inventoryPaths = bootstrapVmPathPhpFiles($repoRoot);
        $byFile = [];
        foreach ($inventoryPaths as $path) {
            $fileIssues = $linter->lintFileStandalone($path);
            if ([] === $fileIssues) {
                continue;
            }
            $rel = substr($path, strlen($repoRoot) + 1);
            $kinds = [];
            foreach ($fileIssues as $issue) {
                $kinds[$issue->kind] = true;
            }
            ksort($kinds, SORT_STRING);
            $byFile[$rel] = array_keys($kinds);
        }
        ksort($byFile, SORT_STRING);
        if ($json) {
            fwrite(STDOUT, json_encode(['files' => $byFile], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        } else {
            foreach ($byFile as $rel => $kinds) {
                fwrite(STDOUT, $rel.': '.implode(', ', $kinds)."\n");
            }
            $scanned = count($inventoryPaths);
            if ([] === $byFile) {
                fwrite(STDOUT, "bootstrap-inventory: 0 files with unsupported syntax ({$scanned} scanned)\n");
            } else {
                fwrite(STDOUT, 'bootstrap-inventory: '.count($byFile)." file(s) with unsupported syntax ({$scanned} scanned)\n");
            }
        }
        exit($check && [] !== $byFile ? 1 : 0);
    }
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
    fwrite(STDOUT, json_encode(['issues' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
} else {
    foreach ($issues as $issue) {
        fwrite(STDOUT, $issue->formatHuman()."\n");
    }
}

exit([] === $issues ? 0 : 1);
