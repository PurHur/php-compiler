#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FastCGI listener for VM web projects (issue #173).
 *
 * Usage:
 *   php bin/fcgi.php --listen 127.0.0.1:9000 examples/009-FastCGIWeb
 *   php bin/fcgi.php --binary examples/009-FastCGIWeb/.phpc/bin/app examples/009-FastCGIWeb
 *   phpc fcgi --listen 127.0.0.1:9000 examples/009-FastCGIWeb
 */

require __DIR__.'/../src/tokenizer-compat.php';
require __DIR__.'/../src/yay-php8-compat.php';
require __DIR__.'/../src/llvm-env.php';
require __DIR__.'/../vendor/autoload.php';

use PHPCompiler\Web\FastCgi\Listener;
use PHPCompiler\Web\CgiAotDriver;
use PHPCompiler\Web\ProjectManifest;

$listen = '127.0.0.1:9000';
$docrootArg = getcwd();
$aotBinary = null;
$args = array_slice($argv, 1);
while ([] !== $args) {
    $arg = array_shift($args);
    if ('--listen' === $arg) {
        $listen = array_shift($args) ?? '';
        if ('' === $listen) {
            fwrite(STDERR, "fcgi: --listen requires host:port\n");
            exit(1);
        }
        continue;
    }
    if (str_starts_with($arg, '--listen=')) {
        $listen = substr($arg, strlen('--listen='));
        continue;
    }
    if ('--binary' === $arg) {
        $aotBinary = array_shift($args) ?? '';
        if ('' === $aotBinary) {
            fwrite(STDERR, "fcgi: --binary requires path\n");
            exit(1);
        }
        continue;
    }
    if (str_starts_with($arg, '--binary=')) {
        $aotBinary = substr($arg, strlen('--binary='));
        continue;
    }
    $docrootArg = $arg;
}

$projectDir = ProjectManifest::resolveProjectDir($docrootArg);
$docroot = ProjectManifest::resolvePublicDir($docrootArg);
if (null !== $projectDir) {
    $docroot = ProjectManifest::resolvePublicDir($projectDir);
}

if (null !== $aotBinary) {
    try {
        $aotBinary = CgiAotDriver::resolveBinary($aotBinary, $projectDir);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'fcgi: '.$e->getMessage()."\n");
        exit(1);
    }
}

try {
    Listener::serve($listen, $docroot, $aotBinary);
} catch (\Throwable $e) {
    fwrite(STDERR, 'fcgi: '.$e->getMessage()."\n");
    exit(1);
}
