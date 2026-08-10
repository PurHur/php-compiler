<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: method-scoped closure/arrow TypeError uses Class::{closure} (#29953).
 *
 * Dedicated provider — full VMTest discovery currently dies on unrelated
 * --EXTENSIONS-- phpts, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class ClosureMethodScopeTypeerrorNameVMTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
