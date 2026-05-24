<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/** VM compliance for ClassName::$prop (issue #1225). */
class StaticPropertyFetchVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/language/static_property_fetch.phpt';
        yield 'static_property_fetch' => self::parsePHPT($path, 'static_property_fetch.phpt');
        $untyped = __DIR__.'/cases/language/static_property_untyped.phpt';
        yield 'static_property_untyped' => self::parsePHPT($untyped, 'static_property_untyped.phpt');
    }
}
