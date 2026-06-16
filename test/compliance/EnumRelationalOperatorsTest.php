<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for backed enum relational operators (#8897, #9016, zend_enum.c). */
final class EnumRelationalOperatorsTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'enum_relational_operators.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_relational_operators.phpt',
            'enum_relational_operators.phpt'
        );
        yield 'enum_relational_scalar.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_relational_scalar.phpt',
            'enum_relational_scalar.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
