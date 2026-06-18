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
        $dynamic = __DIR__.'/cases/language/static_property_dynamic.phpt';
        yield 'static_property_dynamic' => self::parsePHPT($dynamic, 'static_property_dynamic.phpt');
        $selfRef = __DIR__.'/cases/language/static_prop_self_ref.phpt';
        yield 'static_prop_self_ref' => self::parsePHPT($selfRef, 'static_prop_self_ref.phpt');
        $write9458 = __DIR__.'/cases/language/static_typed_property_write_9458.phpt';
        yield 'static_typed_property_write_9458' => self::parsePHPT($write9458, 'static_typed_property_write_9458.phpt');
    }
}
