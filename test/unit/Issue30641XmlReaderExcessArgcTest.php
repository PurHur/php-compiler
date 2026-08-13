<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for XMLReader methods (#30641).
 *
 * php-src: ext/xmlreader/php_xmlreader.c / php_xmlreader.stub.php
 */
final class Issue30641XmlReaderExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_xmlreader_excess_argc_30641.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_xmlreader_excess_argc_30641.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "XMLReader::open() expects at most 3 arguments, 4 given\n"
            ."XMLReader::XML() expects at most 3 arguments, 4 given\n"
            ."XMLReader::close() expects exactly 0 arguments, 1 given\n"
            ."XMLReader::read() expects exactly 0 arguments, 1 given\n"
            ."XMLReader::next() expects at most 1 argument, 2 given\n"
            ."XMLReader::expand() expects at most 1 argument, 2 given\n"
            ."XMLReader::getAttribute() expects exactly 1 argument, 2 given\n"
            ."XMLReader::getAttributeNo() expects exactly 1 argument, 2 given\n"
            ."XMLReader::getAttributeNs() expects exactly 2 arguments, 3 given\n"
            ."XMLReader::isValid() expects exactly 0 arguments, 1 given\n"
            ."XMLReader::readInnerXml() expects exactly 0 arguments, 1 given\n"
            ."XMLReader::readOuterXml() expects exactly 0 arguments, 1 given\n"
            ."XMLReader::readString() expects exactly 0 arguments, 1 given\n"
            ."XMLReader::moveToAttribute() expects exactly 1 argument, 2 given\n"
            ."XMLReader::moveToAttributeNo() expects exactly 1 argument, 2 given\n"
            ."XMLReader::moveToAttributeNs() expects exactly 2 arguments, 3 given\n"
            ."XMLReader::moveToFirstAttribute() expects exactly 0 arguments, 1 given\n"
            ."XMLReader::moveToNextAttribute() expects exactly 0 arguments, 1 given\n"
            ."XMLReader::moveToElement() expects exactly 0 arguments, 1 given\n"
            ."XMLReader::lookupNamespace() expects exactly 1 argument, 2 given\n"
            ."XMLReader::setParserProperty() expects exactly 2 arguments, 3 given\n"
            ."XMLReader::getParserProperty() expects exactly 1 argument, 2 given\n"
            ."XMLReader::setSchema() expects exactly 1 argument, 2 given\n"
            ."XMLReader::setRelaxNGSchema() expects exactly 1 argument, 2 given\n"
            ."XMLReader::setRelaxNGSchemaSource() expects exactly 1 argument, 2 given\n"
            ."LEGAL_OK\n",
            $out
        );
        $this->assertStringNotContainsString('NO_THROW', $out);
    }
}
