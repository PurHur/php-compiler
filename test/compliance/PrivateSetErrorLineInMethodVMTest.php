<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * Slash-free data-set name so --filter works (path-style VMTest names break the regex).
 * Covers #29665 — private(set) Error::getLine() is the assign in the method, not the caller.
 */
final class PrivateSetErrorLineInMethodVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'asymmetric_visibility_forward_84_private_set_error_line.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/asymmetric_visibility_forward_84_private_set_error_line.phpt',
            'asymmetric_visibility_forward_84_private_set_error_line.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
