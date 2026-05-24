<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/** VM compliance for late static binding (issue #1231). */
class LateStaticBindingVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/language/late_static_binding.phpt';
        yield 'late_static_binding' => self::parsePHPT($path, 'late_static_binding.phpt');
    }
}
