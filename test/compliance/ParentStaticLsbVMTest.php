<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/** VM: parent:: static dispatch preserves late-static scope (#12245). */
class ParentStaticLsbVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/language/parent_static_lsb.phpt';
        yield 'parent_static_lsb' => self::parsePHPT($path, 'parent_static_lsb.phpt');
    }
}
