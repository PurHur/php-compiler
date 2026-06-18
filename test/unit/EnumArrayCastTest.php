<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** Issue #5536 — (array) cast on enum cases (Zend zend_enum_to_array). */
final class EnumArrayCastTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'enum_array_cast_unit.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/enum_array_cast_unit.phpt',
            'enum_array_cast_unit.phpt'
        );
        yield 'enum_array_cast_backed.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/enum_array_cast_backed.phpt',
            'enum_array_cast_backed.phpt'
        );
        yield 'enum_object_cast.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/enum_object_cast.phpt',
            'enum_object_cast.phpt'
        );
    }
}
