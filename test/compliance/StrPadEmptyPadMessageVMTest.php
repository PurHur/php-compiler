<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: str_pad() empty/null $pad_string ValueError wording (#29755 / #29292).
 * Default profile: "must be a non-empty string"; PROFILE=8.4: "must not be empty".
 */
final class StrPadEmptyPadMessageVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'str_pad_empty_pad_must_not_be_empty.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_pad_empty_pad_must_not_be_empty.phpt',
            'str_pad_empty_pad_must_not_be_empty.phpt'
        );
        yield 'str_pad_null_pad_non_empty_string.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_pad_null_pad_non_empty_string.phpt',
            'str_pad_null_pad_non_empty_string.phpt'
        );
        yield 'str_pad_empty_pad_must_not_be_empty_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_pad_empty_pad_must_not_be_empty_forward84.phpt',
            'str_pad_empty_pad_must_not_be_empty_forward84.phpt'
        );
        yield 'str_pad_null_pad_must_not_be_empty_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_pad_null_pad_must_not_be_empty_forward84.phpt',
            'str_pad_null_pad_must_not_be_empty_forward84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
