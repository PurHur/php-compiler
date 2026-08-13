<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: XMLWriter methods excess argc → ArgumentCountError (#30818). */
final class XmlWriterExcessArgc30818JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_xmlwriter_30818_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_xmlwriter_30818_jit.phpt',
            'excess_argc_xmlwriter_30818_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
