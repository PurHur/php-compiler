<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/JITTest.php';

/** JIT: static::CONST late static binding uses called class (#19614). */
class StaticClassConstLsbJITTest extends JITTest
{
    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/language/static_class_const_lsb.phpt';
        yield 'static_class_const_lsb' => self::parsePHPT($path, 'static_class_const_lsb.phpt');
    }
}
