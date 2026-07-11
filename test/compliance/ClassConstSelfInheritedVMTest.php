<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';
require_once __DIR__.'/ClassMemberConstVMTest.php';

/** VM compliance: inherited class constants via self:: in child (#13532). */
class ClassConstSelfInheritedVMTest extends ClassMemberConstVMTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'class_const_self_inherited.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/class_const_self_inherited.phpt',
            'class_const_self_inherited.phpt'
        );
    }
}
