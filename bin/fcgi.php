#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FastCGI listener for VM web projects (issue #173, CLI #2427).
 *
 * Usage:
 *   php bin/fcgi.php --listen 127.0.0.1:9000 examples/009-FastCGIWeb
 *   php bin/fcgi.php --project examples/009-FastCGIWeb
 *   phpc fcgi --listen 127.0.0.1:9000 --project examples/009-FastCGIWeb
 */

require __DIR__.'/../src/tokenizer-compat.php';
require __DIR__.'/../src/yay-php8-compat.php';
require __DIR__.'/../src/llvm-env.php';
require __DIR__.'/../vendor/autoload.php';

exit(\PHPCompiler\Cli\PhpcFcgi::main(array_slice($argv, 1)));
