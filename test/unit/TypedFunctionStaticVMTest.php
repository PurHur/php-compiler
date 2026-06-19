<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for typed function-local static variables (#9998). */
final class TypedFunctionStaticVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'typed_function_static.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/typed_function_static.phpt',
            'typed_function_static.phpt'
        );
    }
}
