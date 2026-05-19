#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Lint PHP sources for constructs the static compiler cannot lower yet.
 *
 * Usage:
 *   bin/lint.php [-r 'code'] [--json] <file.php>
 *   phpc lint [-r 'code'] [--json] <file.php>
 */

use PHPCompiler\Lint\Linter;

require __DIR__.'/../src/tokenizer-compat.php';
require __DIR__.'/../src/yay-php8-compat.php';
require __DIR__.'/../vendor/autoload.php';

$json = false;
$code = null;
$filename = null;
$args = array_slice($argv, 1);

while ([] !== $args) {
    $arg = array_shift($args);
    if ('--json' === $arg) {
        $json = true;
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
    if (!is_file($arg)) {
        fwrite(STDERR, "Could not open file {$arg}\n");
        exit(1);
    }
    $filename = $arg;
    $code = (string) file_get_contents($arg);
}

if (null === $filename) {
    fwrite(STDERR, "Usage: lint.php [-r 'code'] [--json] <file.php>\n");
    exit(1);
}

$linter = new Linter();

try {
    $issues = $linter->lintSource($code, $filename);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}

if ($json) {
    $payload = array_map(static fn ($i) => $i->toArray(), $issues);
    fwrite(STDOUT, json_encode(['issues' => $payload], JSON_PRETTY_PRINT)."\n");
} else {
    foreach ($issues as $issue) {
        fwrite(STDOUT, $issue->formatHuman()."\n");
    }
}

exit([] === $issues ? 0 : 1);
