<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ReflectionFunctionAbstract introspection + getClosure excess argc (#30924). */
final class ReflectionFunctionAbstractExcessArgc30924VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_function_abstract_30924.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflection_function_abstract_30924.phpt',
            'excess_argc_reflection_function_abstract_30924.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
