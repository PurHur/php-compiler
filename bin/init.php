#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Scaffold a minimal web project (phpc.json + public/index.php).
 *
 * Usage:
 *   bin/init.php [--force] [target-dir]
 *   phpc init [--force] [target-dir]
 */

use PHPCompiler\Cli\PhpcInit;

require __DIR__.'/../vendor/autoload.php';

$repoRoot = realpath(__DIR__.'/..') ?: __DIR__.'/..';
$force = false;
$target = '.';
$args = array_slice($argv, 1);

while ([] !== $args) {
    $arg = array_shift($args);
    if ('--force' === $arg) {
        $force = true;
        continue;
    }
    if (in_array($arg, ['-h', '--help'], true)) {
        fwrite(STDOUT, <<<'HELP'
phpc init — scaffold a minimal web project

  phpc init [--force] [target-dir]

Writes phpc.json, public/index.php, and README.md (lint-clean defaults).

HELP);
        exit(0);
    }
    $target = $arg;
}

exit(PhpcInit::run($repoRoot, $target, $force));
