<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: pow(null) silent coerce; fpow still soft-null DEP (#29322, re-#20951). */
final class PowNullSilentVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'pow_null_silent_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/pow_null_silent_forward84.phpt',
            'pow_null_silent_forward84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
