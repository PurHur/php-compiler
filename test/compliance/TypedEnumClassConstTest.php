<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for enum-typed class constants (#9790, Zend/zend_compile.c). */
final class TypedEnumClassConstTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'typed_enum_class_const.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/typed_enum_class_const.phpt',
            'typed_enum_class_const.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
