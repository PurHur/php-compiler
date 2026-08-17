<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: isset/empty/?? on dim of uninitialized typed properties is BP_VAR_IS (#31783).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class TypedPropIssetDimUninit31783VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'typed_prop_isset_dim_uninit_31783.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/typed_prop_isset_dim_uninit_31783.phpt',
            'typed_prop_isset_dim_uninit_31783.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
