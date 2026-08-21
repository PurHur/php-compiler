<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: set_exception_handler Reflection callback + named args (#23456).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class SetExceptionHandlerReflection23456VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'set_exception_handler_reflection_23456.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/set_exception_handler_reflection_23456.phpt',
            'set_exception_handler_reflection_23456.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
