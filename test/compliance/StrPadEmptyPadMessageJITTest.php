<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: str_pad() empty/null $pad_string ValueError wording (#29755 / #29292).
 * NestedJIT StrPadJitHelper keeps reference-profile "must be a non-empty string"
 * even under PROFILE=8.4 (no version_compare in NestedJIT; #29755).
 */
final class StrPadEmptyPadMessageJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'str_pad_empty_pad_must_not_be_empty_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_pad_empty_pad_must_not_be_empty_jit.phpt',
            'str_pad_empty_pad_must_not_be_empty_jit.phpt'
        );
        yield 'str_pad_null_pad_non_empty_string.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_pad_null_pad_non_empty_string.phpt',
            'str_pad_null_pad_non_empty_string.phpt'
        );
        yield 'str_pad_empty_pad_must_not_be_empty_forward84_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_pad_empty_pad_must_not_be_empty_forward84_jit.phpt',
            'str_pad_empty_pad_must_not_be_empty_forward84_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
