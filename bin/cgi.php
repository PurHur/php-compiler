#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CGI/1.1 driver for VM scripts (issue #50).
 *
 * nginx/apache set REQUEST_METHOD, QUERY_STRING, CONTENT_LENGTH, etc.; this reads
 * the POST body from stdin, runs the script, and prints Status + headers + body.
 *
 * Usage: php bin/cgi.php /path/to/script.php
 */

require __DIR__.'/../src/tokenizer-compat.php';
require __DIR__.'/../src/yay-php8-compat.php';
require __DIR__.'/../src/llvm-env.php';
require __DIR__.'/../vendor/autoload.php';

use PHPCompiler\Web\CgiDriver;
use PHPCompiler\Web\DevServer;

$script = $argv[1] ?? null;
if (null === $script || !is_file($script)) {
    fwrite(STDERR, "Usage: php bin/cgi.php <script.php>\n");
    exit(1);
}

CgiDriver::ingestStdinRequestBody();

try {
    [$status, $contentType, $output, $extraHeaders] = CgiDriver::runVmScript($script);
} catch (\Throwable $e) {
    DevServer::logException($e);
    $body = DevServer::formatExceptionBody($e);
    fwrite(STDOUT, CgiDriver::formatResponse(500, 'text/plain', $body));
    exit(0);
}

fwrite(STDOUT, CgiDriver::formatResponse($status, $contentType, $output, $extraHeaders));
exit(0);
