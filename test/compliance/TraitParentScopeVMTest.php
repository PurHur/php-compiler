<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/** VM: parent:: in trait methods uses composing class parent (#18878). */
class TraitParentScopeVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/language/trait_parent_scope/parent_method_composing.phpt';
        yield 'trait_parent_method_composing' => self::parsePHPT($path, 'parent_method_composing.phpt');
    }
}
