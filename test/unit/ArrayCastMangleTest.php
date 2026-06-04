<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for (array) object cast visibility mangling (issue #5338). */
final class ArrayCastMangleTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'array_cast_visibility_mangle.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/array_cast_visibility_mangle.phpt',
            'array_cast_visibility_mangle.phpt'
        );
        yield 'array_cast_uninit_typed_property.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/array_cast_uninit_typed_property.phpt',
            'array_cast_uninit_typed_property.phpt'
        );
    }
}
