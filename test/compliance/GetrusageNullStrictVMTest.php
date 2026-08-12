<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: getrusage(null) TypeError under strict_types (#30361). */
final class GetrusageNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'getrusage_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/getrusage_null_strict.phpt',
            'getrusage_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
