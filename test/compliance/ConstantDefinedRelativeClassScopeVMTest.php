<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';
require_once __DIR__.'/ClassMemberConstVMTest.php';

/**
 * VM: constant()/defined() resolve self::/static::/parent:: (#29455).
 */
class ConstantDefinedRelativeClassScopeVMTest extends ClassMemberConstVMTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'constant_defined_relative_class_scope.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/constant_defined_relative_class_scope.phpt',
            'constant_defined_relative_class_scope.phpt'
        );
    }
}
