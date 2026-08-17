<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: dim-assign/append on uninitialized typed array property auto-inits [] (#31770).
 *
 * Dedicated provider — full VMTest discovery currently dies on unrelated
 * --EXTENSIONS-- phpts, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class UninitTypedArrayDimAssignVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'uninit_typed_array_dim_assign.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/uninit_typed_array_dim_assign.phpt',
            'uninit_typed_array_dim_assign.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
