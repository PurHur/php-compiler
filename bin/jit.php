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

require_once __DIR__.'/../src/cli.php';
