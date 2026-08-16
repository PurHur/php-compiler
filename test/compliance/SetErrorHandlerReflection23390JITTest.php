<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: set_error_handler Reflection callback/error_levels + named args (#23390).
 */
final class SetErrorHandlerReflection23390JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'set_error_handler_reflection_23390.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/set_error_handler_reflection_23390.phpt',
            'set_error_handler_reflection_23390.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
