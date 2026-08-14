<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: SimpleXML methods/import excess argc → ArgumentCountError (#30828). */
final class SimpleXmlExcessArgc30828JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_simplexml_30828_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_simplexml_30828_jit.phpt',
            'excess_argc_simplexml_30828_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
