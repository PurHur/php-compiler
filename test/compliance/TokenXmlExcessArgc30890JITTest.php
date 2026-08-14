<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: token_get_all/xml_parser_create/xml_parse excess argc → ArgumentCountError (#30890). */
final class TokenXmlExcessArgc30890JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_token_xml_30890_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_token_xml_30890_jit.phpt',
            'excess_argc_token_xml_30890_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
