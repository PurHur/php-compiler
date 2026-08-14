<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: ReflectionFunctionAbstract introspection + getClosure excess argc (#30924). */
final class ReflectionFunctionAbstractExcessArgc30924JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_function_abstract_30924_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflection_function_abstract_30924_jit.phpt',
            'excess_argc_reflection_function_abstract_30924_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
