<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: token_get_all/xml_parser_create/xml_parse excess argc → ArgumentCountError (#30890). */
final class TokenXmlExcessArgc30890VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_token_xml_30890.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_token_xml_30890.phpt',
            'excess_argc_token_xml_30890.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
