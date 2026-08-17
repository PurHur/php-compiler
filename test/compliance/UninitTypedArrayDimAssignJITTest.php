<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: dim-assign/append on uninitialized typed array property auto-inits [] (#31770). */
final class UninitTypedArrayDimAssignJITTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
