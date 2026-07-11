<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for htmlspecialchars() (#3786 double_encode). */
final class HtmlspecialcharsVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'htmlspecialchars_double_encode.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/htmlspecialchars_double_encode.phpt',
            'htmlspecialchars_double_encode.phpt'
        );
        yield 'htmlspecialchars_null_encoding.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/htmlspecialchars_null_encoding.phpt',
            'htmlspecialchars_null_encoding.phpt'
        );
        yield 'htmlspecialchars_double_encode_named.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/htmlspecialchars_double_encode_named.phpt',
            'htmlspecialchars_double_encode_named.phpt'
        );
        yield 'htmlspecialchars_combined_flags.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/htmlspecialchars_combined_flags.phpt',
            'htmlspecialchars_combined_flags.phpt'
        );
        yield 'htmlspecialchars_ent_quotes_double_encode.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/htmlspecialchars_ent_quotes_double_encode.phpt',
            'htmlspecialchars_ent_quotes_double_encode.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
