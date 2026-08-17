<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: isset/empty/?? on dim of uninitialized typed instance properties is BP_VAR_IS (#31783).
 *
 * Static dim forms are VM-covered in TypedPropIssetDimUninit31783VMTest — JIT
 * TYPE_STATIC_PROPERTY_FETCH + dim isset still fails module verify on master.
 */
final class TypedPropIssetDimUninit31783JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'typed_prop_isset_dim_uninit_instance_31783.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/typed_prop_isset_dim_uninit_instance_31783.phpt',
            'typed_prop_isset_dim_uninit_instance_31783.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
