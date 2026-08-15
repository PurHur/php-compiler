<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: simplexml_load_string() Entity + snippet + caret warnings (#31183). */
final class SimplexmlLoadStringParserWarn31183VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'simplexml_load_string_parser_warn_triple.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/simplexml_load_string_parser_warn_triple.phpt',
            'simplexml_load_string_parser_warn_triple.phpt'
        );
        // #28658 error-count guard — must not triple-record under use_internal_errors.
        yield 'load_string_tag_mismatch_premature.phpt' => self::parsePHPT(
            __DIR__.'/cases/simplexml/load_string_tag_mismatch_premature.phpt',
            'load_string_tag_mismatch_premature.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
