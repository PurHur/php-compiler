<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: dim-assign/append on uninitialized non-array typed property → TypeError (#31819).
 */
require_once __DIR__.'/../BaseTest.php';

final class AssignDimNonarrayTypedUninitVMTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
