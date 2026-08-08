<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for XMLReader::open/XML Reflection (#28712). */
final class XmlReaderOpenXmlReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'open_xml_reflection.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/xmlreader/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
