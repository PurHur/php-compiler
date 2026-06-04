<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/** VM compliance for property default `new` expressions (#3391, #5362). */
final class PropertyDefaultNewVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'property_default_new' => self::parsePHPT(
            __DIR__.'/cases/language/property_default_new.phpt',
            'property_default_new.phpt'
        );
    }
}
