<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: dim ++/+= on uninitialized typed array property Errors (#31784). */
final class UninitTypedArrayDimRwJITTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
