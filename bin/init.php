#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Scaffold a minimal web project (phpc.json + public/index.php).
 *
 * Usage:
 *   bin/init.php [--profile default|miniwebapp|sessionsweb|apijson] [--force] [target-dir]
 *   phpc init [--profile miniwebapp|sessionsweb|apijson] [--force] [target-dir]
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

  phpc init [--profile default|miniwebapp|sessionsweb|apijson] [--force] [target-dir]

Profiles:
  default     phpc.json + public/index.php hello page (issue #312)
  miniwebapp  Router + templates scaffold (issue #632; see examples/003-MiniWebApp)
  sessionsweb Flat example.php session flash (issue #1886; see examples/005-SessionsWeb)
  apijson     Flat example.php JSON API (issue #2000; see examples/004-ApiJson)

HELP);
        exit(0);
    }
    $target = $arg;
}

exit(PhpcInit::run($repoRoot, $target, $force, $profile));
