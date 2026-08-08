<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/** VM: self::/parent::/static:: with no class scope — Zend access message (#29096). */
class SelfParentCallNoClassScopeVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/language/self_parent_call_no_class_scope.phpt';
        yield 'self_parent_call_no_class_scope' => self::parsePHPT(
            $path,
            'self_parent_call_no_class_scope.phpt'
        );
    }
}
