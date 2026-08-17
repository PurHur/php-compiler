<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: dim ++/+= on uninitialized typed array property Errors (#31784).
 *
 * Dedicated provider — full VMTest discovery currently dies on unrelated
 * --EXTENSIONS-- phpts, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class UninitTypedArrayDimRwVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'uninit_typed_array_dim_rw.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/uninit_typed_array_dim_rw.phpt',
            'uninit_typed_array_dim_rw.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
