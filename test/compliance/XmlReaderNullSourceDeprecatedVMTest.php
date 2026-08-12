<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: XMLReader::XML/open(null) Deprecated + ValueError empty (#30563). */
final class XmlReaderNullSourceDeprecatedVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'xmlreader_null_source_deprecated.phpt' => self::parsePHPT(
            __DIR__.'/cases/xmlreader/xmlreader_null_source_deprecated.phpt',
            'xmlreader_null_source_deprecated.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
