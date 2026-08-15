<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: str_word_count null $format under strict_types → TypeError (#31287).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class StrWordCountNullFormatStrict31287VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'str_word_count_null_format_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_word_count_null_format_strict.phpt',
            'str_word_count_null_format_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
