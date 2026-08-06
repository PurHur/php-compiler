<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance: float array dim read/isset/unset E_DEPRECATED (#27948). */
final class FloatDimFetchDeprecatedVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'float_dim_fetch_deprecated.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/float_dim_fetch_deprecated.phpt',
            'float_dim_fetch_deprecated.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
