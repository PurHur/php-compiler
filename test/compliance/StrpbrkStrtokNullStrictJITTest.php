<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: strpbrk/strtok(null) TypeError under strict_types (#29784). */
final class StrpbrkStrtokNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'strpbrk_strtok_null_strict_typeerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/strpbrk_strtok_null_strict_typeerror_jit.phpt',
            'strpbrk_strtok_null_strict_typeerror_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
