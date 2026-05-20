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

require_once __DIR__.'/../src/cli.php';
