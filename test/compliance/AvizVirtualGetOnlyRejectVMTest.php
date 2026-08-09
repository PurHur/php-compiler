<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for #29426 get-only virtual + asymmetric visibility reject
 * (Zend/zend_inheritance.c zend_verify_hooked_property).
 *
 * Isolated provider — avoids full VMTest data-provider walk for a single-case lock.
 */
final class AvizVirtualGetOnlyRejectVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'aviz_virtual_get_only_reject_84.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/aviz_virtual_get_only_reject_84.phpt',
            'aviz_virtual_get_only_reject_84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
