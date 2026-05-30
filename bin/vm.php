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
    Superglobals::populateFromEnvironment(
        $runtime->vmContext,
        is_string($queryString) ? $queryString : null,
        is_string($postBody) ? $postBody : null,
        $scriptFilename
    );
    $block = $runtime->parseAndCompile($code, $filename);
    if (! isset($options['-l'])) {
        try {
            $runtime->run($block);
        } catch (PHPCompiler\VM\ScriptExit $e) {
            exit($e->status);
        }
    }
}

if (
    !(defined('PHP_COMPILER_LIB_SPINE_SMOKE') && PHP_COMPILER_LIB_SPINE_SMOKE)
    && !(\function_exists('php_compiler_cli_should_skip_entry_driver') && php_compiler_cli_should_skip_entry_driver())
) {
    // Use literal require paths so self-host AOT/JIT can fold includes (#54, #1492).
    require_once __DIR__.'/../src/cli.php';
    php_compiler_cli_note_invocation_cwd();
    chdir(__DIR__.'/..');
    require_once 'src/cli.php';
    require_once 'src/cli_driver.php';
    php_compiler_cli_dispatch();
}
