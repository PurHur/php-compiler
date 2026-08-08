<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for xmlwriter_open_memory/open_uri Reflection return (#28786). */
final class XmlWriterOpenReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'open_memory_uri_reflection_return.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/xmlwriter/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}