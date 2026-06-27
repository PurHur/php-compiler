<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\SourcePreprocessor\PropertyHooks;

/**
 * Reject PHP 8.4 property-hook syntax on the Zend 8.2 reference profile (#12574).
 *
 * Must run before {@see PropertyHooks} so hook blocks are not lowered into parseable PHP.
 * php-src: Zend/zend_language_parser.y / Zend/zend_compile.c (PHP 8.4 property hooks).
 */
final class PropertyHookRejector
{
    /** php-src 8.2 profile: `$prop {` without default initializer before hook block. */
    public const UNEXPECTED_BRACE_MESSAGE = 'syntax error, unexpected token "{", expecting "," or ";"';

    /** php-src 8.2 profile: `$prop = expr { get =>` — fat arrow inside hook block. */
    public const UNEXPECTED_ARROW_MESSAGE = 'syntax error, unexpected token "=>"';

    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            return $code;
        }
        $located = (new PropertyHooks())->locateFirstPropertyHookViolation($code);
        if (null === $located) {
            return $code;
        }
        [$line, $message] = $located;
        throw new CompileFatal($filename, $line, $message);
    }
}
