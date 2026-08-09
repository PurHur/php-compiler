<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for #29425 final+private hooked property reject (Zend/zend_compile.c).
 *
 * Isolated provider — avoids full VMTest data-provider walk for a single-case lock.
 */
final class FinalPrivateHookedPropertyRejectVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'final_private_hooked_property_reject_84.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/final_private_hooked_property_reject_84.phpt',
            'final_private_hooked_property_reject_84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
