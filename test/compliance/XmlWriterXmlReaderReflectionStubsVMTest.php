<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: XMLWriter/XMLReader Reflection *Ns/DTD/next stubs (#31867). */
final class XmlWriterXmlReaderReflectionStubsVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'xmlwriter_xmlreader_reflection_stubs.phpt' => self::parsePHPT(
            __DIR__.'/cases/xmlwriter/xmlwriter_xmlreader_reflection_stubs.phpt',
            'xmlwriter_xmlreader_reflection_stubs.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
