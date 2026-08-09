<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * Slash-free data-set name so --filter works (path-style VMTest names break the regex).
 * Covers #29387 — public readonly private(set) modifier orders under PROFILE=8.4.
 */
final class AsymmetricVisibilityReadonlyPrivateSetOrderVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'asymmetric_visibility_forward_84_readonly_private_set_order.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/asymmetric_visibility_forward_84_readonly_private_set_order.phpt',
            'asymmetric_visibility_forward_84_readonly_private_set_order.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
