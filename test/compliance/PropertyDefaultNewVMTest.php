<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/** VM compliance: instance property `new` default compile-rejects (#10693). */
final class PropertyDefaultNewVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'property_default_new_untyped_reject' => self::parsePHPT(
            __DIR__.'/cases/language/property_default_new.phpt',
            'property_default_new.phpt'
        );
        yield 'instance_typed_property_new_reject' => self::parsePHPT(
            __DIR__.'/cases/language/instance_typed_property_new_reject.phpt',
            'instance_typed_property_new_reject.phpt'
        );
    }
}
