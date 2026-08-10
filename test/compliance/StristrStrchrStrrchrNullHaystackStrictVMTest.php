<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: stristr/strchr/strrchr(null) TypeError under strict_types (#29783). */
final class StristrStrchrStrrchrNullHaystackStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stristr_strchr_strrchr_null_haystack_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stristr_strchr_strrchr_null_haystack_strict.phpt',
            'stristr_strchr_strrchr_null_haystack_strict.phpt'
        );
        yield 'stristr_strchr_strrchr_null_haystack_weak.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stristr_strchr_strrchr_null_haystack_weak.phpt',
            'stristr_strchr_strrchr_null_haystack_weak.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
