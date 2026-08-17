<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: &$obj->typed on uninitialized non-nullable property throws Error (#31771).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class UninitTypedPropertyByref31771VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'uninit_typed_property_byref.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/uninit_typed_property_byref.phpt',
            'uninit_typed_property_byref.phpt'
        );
        yield 'settype_uninit_typed_byref.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/settype_uninit_typed_byref.phpt',
            'settype_uninit_typed_byref.phpt'
        );
        yield 'typed_property_assign_via_ref_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/typed_property_assign_via_ref_typeerror.phpt',
            'typed_property_assign_via_ref_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
