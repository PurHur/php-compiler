<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for trait instance methods on enum cases (#5709, #6623). */
final class EnumTraitMethodTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'enum_trait_use.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_trait_use.phpt',
            'enum_trait_use.phpt'
        );
        yield 'enum_trait_method.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_trait_method.phpt',
            'enum_trait_method.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
