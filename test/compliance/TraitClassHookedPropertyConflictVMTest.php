<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * Dedicated provider — slash-free data-set names so --filter works (#30009).
 * Trait+class same hooked property must Fatal like Zend composition conflict.
 */
final class TraitClassHookedPropertyConflictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'trait_class_hooked_property_conflict.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/trait_class_hooked_property_conflict.phpt',
            'trait_class_hooked_property_conflict.phpt'
        );
        yield 'trait_abstract_hook_class_redeclare_conflict.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/trait_abstract_hook_class_redeclare_conflict.phpt',
            'trait_abstract_hook_class_redeclare_conflict.phpt'
        );
        yield 'trait_abstract_hook_subclass_ok.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/trait_abstract_hook_subclass_ok.phpt',
            'trait_abstract_hook_subclass_ok.phpt'
        );
        yield 'trait_abstract_property_hook_ok.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/trait_abstract_property_hook_ok.phpt',
            'trait_abstract_property_hook_ok.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
