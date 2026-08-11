<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: getprotobyname(null) TypeError under strict_types (#30282). */
final class GetprotobynameNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'getprotobyname_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/getprotobyname_null_strict_jit.phpt',
            'getprotobyname_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
