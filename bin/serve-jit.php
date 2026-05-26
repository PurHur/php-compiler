#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * HTTP/1.1 dev server with MCJIT per script (issues #207, #2257).
 *
 * Compiles and JIT-links each PHP entry script once, then refreshes CGI superglobals
 * per request via Runtime::syncJitSuperglobals() before run().
 *
 * Usage: php bin/serve-jit.php [host:port] [docroot]
 * Example: php bin/serve-jit.php 127.0.0.1:8080 examples/001-SimpleWeb
 */

// Avoid RTLD_GLOBAL preload in the long-lived serve process (issue #98).
putenv('PHP_COMPILER_SKIP_LLVM_PRELOAD=1');
$_ENV['PHP_COMPILER_SKIP_LLVM_PRELOAD'] = '1';
$_SERVER['PHP_COMPILER_SKIP_LLVM_PRELOAD'] = '1';

require __DIR__.'/../src/tokenizer-compat.php';
require __DIR__.'/../src/yay-php8-compat.php';
require __DIR__.'/../src/llvm-env.php';
require __DIR__.'/../vendor/autoload.php';

use PHPCompiler\Block;
use PHPCompiler\Runtime;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\ScriptExit;
use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\Web\DevServer;
use PHPCompiler\Web\ProjectBootstrap;
use PHPCompiler\Web\ProjectManifest;
use PHPCompiler\Web\ResponseContext;
use PHPCompiler\Web\Superglobals;

$listen = $argv[1] ?? '127.0.0.1:8080';
$docrootArg = $argv[2] ?? getcwd();
$projectDir = ProjectManifest::resolveProjectDir($docrootArg);
$manifest = null !== $projectDir ? ProjectManifest::loadManifest($projectDir) : null;
$docroot = ProjectManifest::resolvePublicDir($docrootArg);

/** @var array<string, array{0: Runtime, 1: ?Block}> */
$jitCache = [];

DevServer::run($listen, $docroot, static function (string $script, array $cgiEnv) use (&$jitCache): array {
    ResponseContext::reset();
    VmSession::reset();
    OutputBuffer::reset();

    $cacheKey = realpath($script);
    if (false === $cacheKey) {
        $cacheKey = $script;
    }

    if (!isset($jitCache[$cacheKey])) {
        $code = file_get_contents($script);
        if (false === $code) {
            throw new \RuntimeException('Could not read script');
        }

        $runtime = new Runtime();
        Superglobals::populateFromEnvironment(
            $runtime->vmContext,
            $cgiEnv['QUERY_STRING'] ?? '',
            $cgiEnv['REQUEST_BODY'] ?? '',
            $cgiEnv['SCRIPT_FILENAME'] ?? null
        );
        [$bootProjectDir, $bootManifest] = ProjectBootstrap::resolveFromScript($script);
        ProjectBootstrap::prepare($runtime, $bootProjectDir, $bootManifest);
        $block = $runtime->parseAndCompile($code, $script);
        try {
            $runtime->jit($block);
        } catch (\Throwable $e) {
            throw new \RuntimeException('JIT compile failed: '.$e->getMessage(), 0, $e);
        }
        $jitCache[$cacheKey] = [$runtime, $block];
    }

    [$runtime, $block] = $jitCache[$cacheKey];

    ob_start();
    try {
        $runtime->syncJitSuperglobals(
            $cgiEnv['QUERY_STRING'] ?? null,
            $cgiEnv['REQUEST_BODY'] ?? null,
            $cgiEnv['SCRIPT_FILENAME'] ?? null
        );
        $runtime->run($block);
        $output = ob_get_clean();
        if (VmSession::isActive()) {
            VmSession::writeClose($runtime->vmContext);
        }
    } catch (ScriptExit $e) {
        ob_end_clean();
        $output = '';
        if (VmSession::isActive()) {
            VmSession::writeClose($runtime->vmContext);
        }
    } catch (\Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    $responseHeaders = ResponseContext::listHeaders();
    if ([] === $responseHeaders && \function_exists('headers_list')) {
        $responseHeaders = \headers_list();
    }
    if (\function_exists('header_remove')) {
        \header_remove();
    }
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
}, $manifest, $projectDir);
