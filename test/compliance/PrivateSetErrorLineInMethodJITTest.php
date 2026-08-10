<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 *
 * Slash-free data-set name so --filter works.
 * Covers #29665 — private(set) Error::getLine() under bin/jit.php (MCJIT embed + VM fallback).
 */
final class PrivateSetErrorLineInMethodJITTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
