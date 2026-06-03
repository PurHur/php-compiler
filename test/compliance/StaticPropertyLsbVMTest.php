<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/** VM: static::$prop late static binding on inherited properties (#4668). */
class StaticPropertyLsbVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/language/static_property_lsb.phpt';
        yield 'static_property_lsb' => self::parsePHPT($path, 'static_property_lsb.phpt');
    }
}
