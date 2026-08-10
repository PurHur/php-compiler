<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: method-scoped closure/arrow TypeError uses Class::{closure} (#29953).
 *
 * Dedicated provider — same pattern as ClosureMethodScopeTypeerrorNameVMTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class ClosureMethodScopeTypeerrorNameJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'closure_method_scope_typeerror_name.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_method_scope_typeerror_name.phpt',
            'closure_method_scope_typeerror_name.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
