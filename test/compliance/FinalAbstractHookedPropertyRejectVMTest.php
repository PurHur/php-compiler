<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for #29424 final+abstract hooked property reject (Zend/zend_compile.c GH-17916).
 *
 * Isolated provider — avoids full VMTest data-provider walk for a single-case lock.
 */
final class FinalAbstractHookedPropertyRejectVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'final_abstract_hooked_property_reject_84.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/final_abstract_hooked_property_reject_84.phpt',
            'final_abstract_hooked_property_reject_84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
