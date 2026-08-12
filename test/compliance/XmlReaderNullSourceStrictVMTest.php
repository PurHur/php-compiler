<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: XMLReader::XML/open(null) strict_types TypeError (#30563). */
final class XmlReaderNullSourceStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'xmlreader_null_source_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/xmlreader/xmlreader_null_source_strict.phpt',
            'xmlreader_null_source_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
