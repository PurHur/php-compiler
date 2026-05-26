<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';
require_once __DIR__.'/ClassMemberConstVMTest.php';

/**
 * VM compliance: user-defined static methods (#2209).
 */
class ClassStaticMethodVMTest extends ClassMemberConstVMTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'class_static_method.phpt' => self::parsePHPT(
            __DIR__.'/cases/classes/class_static_method.phpt',
            'class_static_method.phpt'
        );
    }
}
