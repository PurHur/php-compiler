<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: getopt(null) TypeError under strict_types (#30358). */
final class GetoptNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'getopt_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/getopt_null_strict.phpt',
            'getopt_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
