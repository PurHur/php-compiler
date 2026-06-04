<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for trait constants merged onto enums (#5719). */
final class EnumTraitConstTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'enum_trait_const.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_trait_const.phpt',
            'enum_trait_const.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
