<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/** VM: parent:: in trait methods uses composing class parent (#18878). */
class TraitParentScopeVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        $dir = __DIR__.'/cases/language/trait_parent_scope';
        yield 'trait_parent_method_composing' => self::parsePHPT(
            $dir.'/parent_method_composing.phpt',
            'parent_method_composing.phpt'
        );
        yield 'trait_overrides_parent_method' => self::parsePHPT(
            $dir.'/trait_overrides_parent_method.phpt',
            'trait_overrides_parent_method.phpt'
        );
    }
}
