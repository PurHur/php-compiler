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
    $iniOverrides = $options['-d'] ?? null;
    if (is_array($iniOverrides)) {
        php_compiler_cli_apply_ini_overrides($runtime->vmContext, $iniOverrides);
    }
    php_compiler_cli_sync_host_error_reporting($runtime->vmContext, $options);
    php_compiler_cli_sync_host_exception_ignore_args($runtime->vmContext, $options);
    php_compiler_cli_sync_host_exception_string_param_max_len($runtime->vmContext, $options);
    php_compiler_cli_sync_host_zend_assertions($runtime->vmContext, $options);
    $queryString = $options['-q'] ?? null;
    $postBody = $options['-p'] ?? null;
    $scriptFilename = null;
    if (!php_compiler_cli_is_virtual_code_filename($filename)) {
        $resolved = realpath($filename);
        if (false !== $resolved) {
            $scriptFilename = $resolved;
        }
    }
    $scriptName = php_compiler_cli_server_script_name($filename, $options);
    Superglobals::populateFromEnvironment(
        $runtime->vmContext,
        is_string($queryString) ? $queryString : null,
        is_string($postBody) ? $postBody : null,
        $scriptFilename,
        $scriptName
    );
    $scriptArgv = $options['--script-argv'] ?? null;
    if (is_array($scriptArgv)) {
        Superglobals::populateCliArgv(
            $runtime->vmContext,
            array_values(array_map('strval', $scriptArgv))
        );
    }
    try {
        $block = $runtime->parseAndCompile($code, $filename);
    } catch (\CompileError $e) {
        exit(255);
    } catch (\LogicException $e) {
        exit(255);
    }
    if (! isset($options['-l'])) {
        try {
            $runtime->run($block, false);
        } catch (PHPCompiler\VM\ScriptExit $e) {
            exit($e->status);
        } catch (\LogicException $e) {
            echo $e->getMessage(), "\n";
            exit(255);
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
