#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Scaffold a minimal web project (phpc.json + public/index.php).
 *
 * Usage:
 *   bin/init.php [--profile default|miniwebapp] [--force] [target-dir]
 *   phpc init [--profile miniwebapp] [--force] [target-dir]
 */

use PHPCompiler\Cli\PhpcInit;

require __DIR__.'/../vendor/autoload.php';

$repoRoot = realpath(__DIR__.'/..') ?: __DIR__.'/..';
$force = false;
$profile = PhpcInit::PROFILE_DEFAULT;
$target = '.';
$args = array_slice($argv, 1);

while ([] !== $args) {
    $arg = array_shift($args);
    if ('--force' === $arg) {
        $force = true;
        continue;
    }
    if ('--profile' === $arg) {
        if ([] === $args) {
            fwrite(STDERR, "phpc init: --profile requires a value\n");
            exit(1);
        }
        $profile = array_shift($args);
        continue;
    }
    if (in_array($arg, ['-h', '--help'], true)) {
        fwrite(STDOUT, <<<'HELP'
phpc init — scaffold a minimal web project

  phpc init [--profile default|miniwebapp] [--force] [target-dir]

Profiles:
  default     phpc.json + public/index.php hello page (issue #312)
  miniwebapp  Router + templates scaffold (issue #632; see examples/003-MiniWebApp)

HELP);
        exit(0);
    }
    $target = $arg;
}

exit(PhpcInit::run($repoRoot, $target, $force, $profile));
