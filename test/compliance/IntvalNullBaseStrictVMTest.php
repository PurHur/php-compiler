<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: intval(null $base) TypeError under strict_types (#31227). */
final class IntvalNullBaseStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'intval_null_base_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/intval_null_base_strict.phpt',
            'intval_null_base_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
