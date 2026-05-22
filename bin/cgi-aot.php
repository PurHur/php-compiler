#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CGI/1.1 wrapper for phpc-built AOT binaries (issue #665).
 *
 * nginx/apache set REQUEST_METHOD, QUERY_STRING, CONTENT_LENGTH, etc.; this reads
 * the POST body from stdin, sets REQUEST_BODY, execs the native binary, and prints
 * its CGI stdout (Status + headers + body).
 *
 * Usage:
 *   php bin/cgi-aot.php /path/to/aot-binary
 *   PHPC_DEPLOY_ROOT=/var/www/myapp php bin/cgi-aot.php
 *   phpc cgi /path/to/aot-binary
 */

require __DIR__.'/../src/tokenizer-compat.php';
require __DIR__.'/../src/yay-php8-compat.php';
require __DIR__.'/../src/llvm-env.php';
require __DIR__.'/../vendor/autoload.php';

use PHPCompiler\Web\CgiAotDriver;
use PHPCompiler\Web\DevServer;

$explicit = $argv[1] ?? null;
$deployRoot = getenv('PHPC_DEPLOY_ROOT');
$deployRoot = false !== $deployRoot ? $deployRoot : null;

try {
    $binary = CgiAotDriver::resolveBinary($explicit, $deployRoot);
    CgiAotDriver::run($binary, $deployRoot);
} catch (\Throwable $e) {
    DevServer::logException($e);
    $body = DevServer::formatExceptionBody($e);
    fwrite(STDOUT, \PHPCompiler\Web\CgiDriver::formatResponse(500, 'text/plain', $body));
    exit(0);
}
