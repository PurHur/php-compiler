<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

use PHPCompiler\Runtime;
use PHPCompiler\Web\Superglobals;

/**
 * Run a script via MCJIT. CGI superglobals refresh each execution (issue #642, #2257):
 * - `-q` / `-p` set QUERY_STRING / REQUEST_BODY (and POST method when body non-empty)
 * - `REQUEST_METHOD`, `PATH_INFO`, `SCRIPT_NAME`, `HTTPS`, etc. come from the process
 *   environment (same keys as VM serve / AOT `__superglobals__refresh`)
 */
function run(string $filename, string $code, array $options): void
{
    $runtime = new Runtime();
    $queryString = $options['-q'] ?? null;
    $postBody = $options['-p'] ?? null;
    $scriptFilename = null;
    if ('-' !== $filename && 'Command line code' !== $filename) {
        $resolved = realpath($filename);
        if (false !== $resolved) {
            $scriptFilename = $resolved;
        }
    }
    $queryArg = is_string($queryString) ? $queryString : null;
    $postArg = is_string($postBody) ? $postBody : null;
    Superglobals::populateFromEnvironment(
        $runtime->vmContext,
        $queryArg,
        $postArg,
        $scriptFilename
    );

    $debugFile = null;
    if (isset($options['-y'])) {
        if ($options['-y'] === true) {
            $debugFile = str_replace('.php', '', $filename);
        } else {
            $debugFile = $options['-y'];
        }
        if (substr($debugFile, 0, 1) !== '/') {
            $debugFile = getcwd().'/'.$debugFile;
        }
        $runtime->setDebug($debugFile);
    }
    $block = $runtime->parseAndCompile($code, $filename);
    $runtime->jit($block);

    if (! isset($options['-l'])) {
        $runtime->syncJitSuperglobals($queryArg, $postArg, $scriptFilename);
        $runtime->run($block);
    }
}

// libffi RTLD_GLOBAL preload before MCJIT segfaults on php-compiler:22.04-dev (#98, #2055).
putenv('PHP_COMPILER_SKIP_LLVM_PRELOAD=1');
$_ENV['PHP_COMPILER_SKIP_LLVM_PRELOAD'] = '1';
$_SERVER['PHP_COMPILER_SKIP_LLVM_PRELOAD'] = '1';

// Use literal require paths so self-host AOT/JIT can fold includes (#54, #1492).
chdir(__DIR__.'/..');
require_once 'src/cli.php';
require_once 'src/cli_driver.php';
