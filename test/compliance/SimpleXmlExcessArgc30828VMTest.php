<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SimpleXML methods/import excess argc → ArgumentCountError (#30828). */
final class SimpleXmlExcessArgc30828VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_simplexml_30828.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_simplexml_30828.phpt',
            'excess_argc_simplexml_30828.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
