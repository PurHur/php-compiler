<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: stristr/strchr/strrchr(null) TypeError under strict_types (#29783). */
final class StristrStrchrStrrchrNullHaystackStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stristr_strchr_strrchr_null_haystack_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stristr_strchr_strrchr_null_haystack_strict_jit.phpt',
            'stristr_strchr_strrchr_null_haystack_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
