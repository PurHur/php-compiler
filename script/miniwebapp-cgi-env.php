#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Emit MiniWebApp CGI env for shell smokes (issue #790).
 *
 *   ./script/miniwebapp-cgi-env.php --json shellQueryRouteHome
 *   eval "$(./script/miniwebapp-cgi-env.php --export shellQueryRouteHome)"
 */
require __DIR__.'/../test/support/MiniWebAppCgiEnv.php';

use PHPCompiler\MiniWebAppCgiEnv;

$repoRoot = dirname(__DIR__);
$format = 'json';
$scenario = '';

foreach (array_slice($argv, 1) as $arg) {
    if ('--json' === $arg) {
        $format = 'json';
        continue;
    }
    if ('--export' === $arg) {
        $format = 'export';
        continue;
    }
    if ('--list' === $arg) {
        foreach (MiniWebAppCgiEnv::scenarioNames() as $name) {
            echo $name, PHP_EOL;
        }
        exit(0);
    }
    if ('--help' === $arg || '-h' === $arg) {
        fwrite(STDERR, "Usage: script/miniwebapp-cgi-env.php [--json|--export] <scenario>\n");
        fwrite(STDERR, "       script/miniwebapp-cgi-env.php --list\n");
        exit(0);
    }
    $scenario = $arg;
}

if ('' === $scenario) {
    fwrite(STDERR, "miniwebapp-cgi-env: missing scenario (try --list)\n");
    exit(1);
}

try {
    $env = MiniWebAppCgiEnv::scenario($scenario, $repoRoot);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'miniwebapp-cgi-env: '.$e->getMessage()."\n");
    exit(1);
}

if ('export' === $format) {
    foreach ($env as $key => $value) {
        echo 'export ', $key, '=', escapeshellarg($value), ';';
    }
    echo PHP_EOL;
    exit(0);
}

echo json_encode($env, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
