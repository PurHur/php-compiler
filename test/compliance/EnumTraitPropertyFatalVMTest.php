<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance: enums cannot import trait properties (#26558, re-#6005).
 */
final class EnumTraitPropertyFatalVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'enum_trait_properties_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_trait_properties_fatal.phpt',
            'enum_trait_properties_fatal.phpt'
        );
        yield 'enum_nested_trait_properties_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_nested_trait_properties_fatal.phpt',
            'enum_nested_trait_properties_fatal.phpt'
        );
        yield 'enum_no_properties.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_no_properties.phpt',
            'enum_no_properties.phpt'
        );
        yield 'enum_trait_use.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_trait_use.phpt',
            'enum_trait_use.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
