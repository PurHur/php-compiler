#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Minimal HTTP/1.1 dev server for web examples (VM mode).
 *
 * Usage: php bin/serve.php [host:port] [docroot]
 * Example: php bin/serve.php 127.0.0.1:8080 examples/001-SimpleWeb
 */

require __DIR__.'/../src/tokenizer-compat.php';
require __DIR__.'/../src/yay-php8-compat.php';
require __DIR__.'/../src/llvm-env.php';
require __DIR__.'/../vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\Web\DevServer;
use PHPCompiler\Web\ResponseContext;
use PHPCompiler\Web\Superglobals;

$listen = $argv[1] ?? '127.0.0.1:8080';
$docroot = $argv[2] ?? getcwd();

DevServer::run($listen, $docroot, static function (string $script, array $cgiEnv): array {
    ResponseContext::reset();
    $code = file_get_contents($script);
    if (false === $code) {
        throw new \RuntimeException('Could not read script');
    }

    ob_start();
    try {
        $runtime = new Runtime();
        Superglobals::populateFromEnvironment(
            $runtime->vmContext,
            $cgiEnv['QUERY_STRING'] ?? '',
            $cgiEnv['REQUEST_BODY'] ?? ''
        );
        $block = $runtime->parseAndCompile($code, $script);
        $runtime->run($block);
        $output = ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    $responseHeaders = headers_list();
    header_remove();
    $status = ResponseContext::getStatus();
    $contentType = 'text/html; charset=UTF-8';
    foreach ($responseHeaders as $line) {
        if (stripos($line, 'Content-Type:') === 0) {
            $contentType = trim(substr($line, strlen('Content-Type:')));
        }
        if (preg_match('#^HTTP/\d\.\d\s+(\d+)#', $line, $sm)) {
            $status = (int) $sm[1];
        }
    }

    return [$status, $contentType, $output, $responseHeaders];
});
