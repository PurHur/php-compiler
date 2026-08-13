<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: XMLWriter methods excess argc → ArgumentCountError (#30818). */
final class XmlWriterExcessArgc30818VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_xmlwriter_30818.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_xmlwriter_30818.phpt',
            'excess_argc_xmlwriter_30818.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
