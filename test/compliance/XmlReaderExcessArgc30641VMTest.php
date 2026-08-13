<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: XMLReader methods excess argc → ArgumentCountError (#30641). */
final class XmlReaderExcessArgc30641VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_xmlreader_30641.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_xmlreader_30641.phpt',
            'excess_argc_xmlreader_30641.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
