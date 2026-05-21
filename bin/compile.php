<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

use PHPCompiler\Runtime;
use PHPCompiler\Web\SourceBundler;
use PHPCompiler\Web\Superglobals;

function run(string $filename, string $code, array $options): void
{
    $includes = $options['--include'] ?? [];
    if (!is_array($includes)) {
        $includes = [] === $includes || '' === $includes ? [] : [$includes];
    }
    /** @var list<string> $includes */
    if ([] !== $includes) {
        [$code, $filename] = SourceBundler::bundleForAot($filename, $includes);
    }

    $runtime = new Runtime(Runtime::MODE_AOT);
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
        if (! isset($options['-o']) || $options['-o'] === true) {
            $options['-o'] = str_replace('.php', '', $filename);
        }
        if (isset($options['-y'])) {
            $debugFile = true === $options['-y'] ? $options['-o'] : $options['-y'];
            $runtime->setDebug($debugFile);
        }
        $runtime->standalone($block, $options['-o']);
    }
}

require_once __DIR__.'/../src/cli.php';
