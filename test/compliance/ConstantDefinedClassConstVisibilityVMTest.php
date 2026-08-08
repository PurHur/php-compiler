<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';
require_once __DIR__.'/ClassMemberConstVMTest.php';

/**
 * VM: constant()/defined() honor private/protected class const visibility (#29130).
 */
class ConstantDefinedClassConstVisibilityVMTest extends ClassMemberConstVMTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'constant_defined_class_const_visibility.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/constant_defined_class_const_visibility.phpt',
            'constant_defined_class_const_visibility.phpt'
        );
    }
}
