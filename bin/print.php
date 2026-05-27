<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

use PHPCfg\Printer\Text as CfgPrinter;
use PHPCompiler\Printer as OpCodePrinter;
use PHPCompiler\Runtime;

function run(string $filename, string $code, array $options): void
{
    $runtime = new Runtime();
    $script = $runtime->parse($code, $filename);
    echo "\nControl Flow Graph: \n";
    echo (new CfgPrinter())->printScript($script);
    $block = $runtime->compile($script);
    echo "\n\nOpCodes:\n\n";
    echo (new OpCodePrinter())->print($block);
}

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
