<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

use PHPCompiler\Runtime;
use PHPCompiler\Block;
use PHPCompiler\OpCode;
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
    if (null !== $block && jit_block_contains_trycatch($block)) {
        // JIT EH lowering is not yet stable; try/catch currently segfaults in MCJIT (issue #2114).
        // Fall back to VM semantics rather than producing silent miscompiles or hard crashes.
        //
        // This keeps JIT usable for the rest of the language while #2114 is implemented.
    } else {
        $runtime->jit($block);
    }

    if (! isset($options['-l'])) {
        $runtime->syncJitSuperglobals($queryArg, $postArg, $scriptFilename);
        $runtime->run($block);
    }
}

function jit_block_contains_trycatch(Block $block, ?\SplObjectStorage $seen = null): bool
{
    if (null === $seen) {
        $seen = new \SplObjectStorage();
    }
    if ($seen->contains($block)) {
        return false;
    }
    $seen->attach($block);
    foreach ($block->opCodes as $op) {
        if (
            OpCode::TYPE_TRY === $op->type
            || OpCode::TYPE_CATCH === $op->type
            || OpCode::TYPE_FINALLY === $op->type
            || OpCode::TYPE_THROW === $op->type
            || OpCode::TYPE_YIELD === $op->type
            || OpCode::TYPE_YIELD_FROM === $op->type
        ) {
            return true;
        }
        foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
            if ($sub instanceof Block && jit_block_contains_trycatch($sub, $seen)) {
                return true;
            }
        }
    }

    return false;
}

// libffi RTLD_GLOBAL preload before MCJIT segfaults on php-compiler:22.04-dev (#98, #2055).
putenv('PHP_COMPILER_SKIP_LLVM_PRELOAD=1');
$_ENV['PHP_COMPILER_SKIP_LLVM_PRELOAD'] = '1';
$_SERVER['PHP_COMPILER_SKIP_LLVM_PRELOAD'] = '1';

if (
    !(defined('PHP_COMPILER_LIB_SPINE_SMOKE') && PHP_COMPILER_LIB_SPINE_SMOKE)
    && !(\function_exists('php_compiler_cli_should_skip_entry_driver') && php_compiler_cli_should_skip_entry_driver())
) {
    // Use literal require paths so self-host AOT/JIT can fold includes (#54, #1492).
    chdir(__DIR__.'/..');
    require_once 'src/cli.php';
    require_once 'src/cli_driver.php';
    php_compiler_cli_dispatch();
}
