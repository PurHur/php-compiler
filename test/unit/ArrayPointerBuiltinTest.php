<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for array pointer builtins (#4967, #5504). */
final class ArrayPointerBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'array_pointer.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/array_pointer.phpt',
            'array_pointer.phpt'
        );
        yield 'key_after_unset.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/key_after_unset.phpt',
            'key_after_unset.phpt'
        );
        yield 'array_pointer_end.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/array_pointer_end.phpt',
            'array_pointer_end.phpt'
        );
    }
}
