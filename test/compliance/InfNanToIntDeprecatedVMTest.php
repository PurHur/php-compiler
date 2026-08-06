<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance: INF/NAN→int untyped E_DEPRECATED (#27926). */
final class InfNanToIntDeprecatedVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'inf_nan_to_int_deprecated.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/inf_nan_to_int_deprecated.phpt',
            'inf_nan_to_int_deprecated.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
