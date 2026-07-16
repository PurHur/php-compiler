<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';
require_once __DIR__.'/ClassMemberConstVMTest.php';

/**
 * VM compliance: private parent class const must not leak via child self:: (#19615).
 */
class ClassConstPrivateSelfChildVMTest extends ClassMemberConstVMTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'class_const_private_self_child.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/class_const_private_self_child.phpt',
            'class_const_private_self_child.phpt'
        );
    }
}
