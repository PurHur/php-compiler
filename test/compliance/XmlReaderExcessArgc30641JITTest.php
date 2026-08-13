<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: XMLReader methods excess argc → ArgumentCountError (#30641). */
final class XmlReaderExcessArgc30641JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_xmlreader_30641_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_xmlreader_30641_jit.phpt',
            'excess_argc_xmlreader_30641_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
