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
use PHPCompiler\JitMcjitEmbed;
use PHPCompiler\Web\Superglobals;

function php_compiler_jit_prepare_embed_code(string $filename, string $code): string
{
    return JitMcjitEmbed::prepareClassless($code);
}

/**
 * Run a script via MCJIT. CGI superglobals refresh each execution (issue #642, #2257):
 * - `-q` / `-p` set QUERY_STRING / REQUEST_BODY (and POST method when body non-empty)
 * - `REQUEST_METHOD`, `PATH_INFO`, `SCRIPT_NAME`, `HTTPS`, etc. come from the process
 *   environment (same keys as VM serve / AOT `__superglobals__refresh`)
 */
function run(string $filename, string $code, array $options): void
{
    $userSource = $code;
    $code = php_compiler_jit_prepare_embed_code($filename, $code);
    $runtime = new Runtime();
    $iniOverrides = $options['-d'] ?? null;
    if (is_array($iniOverrides)) {
        php_compiler_cli_apply_ini_overrides($runtime->vmContext, $iniOverrides);
    }
    $queryString = $options['-q'] ?? null;
    $postBody = $options['-p'] ?? null;
    $scriptFilename = null;
    if (!php_compiler_cli_is_virtual_code_filename($filename)) {
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
    $scriptArgv = $options['--script-argv'] ?? null;
    if (is_array($scriptArgv)) {
        Superglobals::populateCliArgv(
            $runtime->vmContext,
            array_values(array_map('strval', $scriptArgv))
        );
    }

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
    if (null !== $block) {
        $runtime->compiler->reconcileHaltCompilerOffsetFromSource($userSource);
        if (null !== $runtime->compiler->getHaltCompilerOffset()) {
            $block->haltCompilerOffset = $runtime->compiler->getHaltCompilerOffset();
        }
    }
    if (null !== $block && Block::requiresVmLowering($block)) {
        // Generators, readonly, fibers, typed returns in script scope, etc. still VM-fallback (#2114).
        // Script-scope try/catch/throw uses MCJIT via TryCatchHelper (#4246, #4137).
        // finally in script scope still VM-fallback until #2114 phase B.
    } else {
        $runtime->jit($block, $code, $filename);
    }

    if (! isset($options['-l'])) {
        $runtime->syncJitSuperglobals($queryArg, $postArg, $scriptFilename);
        $runtime->run($block);
    }
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
    require_once __DIR__.'/../src/cli.php';
    php_compiler_cli_note_invocation_cwd();
    chdir(__DIR__.'/..');
    require_once 'src/cli.php';
    require_once 'src/cli_driver.php';
    php_compiler_cli_dispatch();
}
