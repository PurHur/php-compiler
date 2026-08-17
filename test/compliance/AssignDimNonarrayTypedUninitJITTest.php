<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: dim-assign/append on uninitialized non-array typed property → TypeError (#31819). */
final class AssignDimNonarrayTypedUninitJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'assign_dim_nonarray_typed_uninit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/assign_dim_nonarray_typed_uninit.phpt',
            'assign_dim_nonarray_typed_uninit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
