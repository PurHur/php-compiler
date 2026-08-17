<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: foreach-by-ref on uninitialized typed property uses by-ref Error (#31836).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ForeachByrefUninitTyped31836VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'foreach_byref_uninit_typed_property_31836.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/foreach_byref_uninit_typed_property_31836.phpt',
            'foreach_byref_uninit_typed_property_31836.phpt'
        );
        // Regression: &$obj->typed path from #31771 must stay green.
        yield 'uninit_typed_property_byref.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/uninit_typed_property_byref.phpt',
            'uninit_typed_property_byref.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
