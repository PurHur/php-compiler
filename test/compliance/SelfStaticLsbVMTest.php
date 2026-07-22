<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/** VM: self:: static dispatch preserves late-static scope (#21983). */
class SelfStaticLsbVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/language/self_static_lsb.phpt';
        yield 'self_static_lsb' => self::parsePHPT($path, 'self_static_lsb.phpt');
    }
}
