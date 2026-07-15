<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\xmlreader\XmlReaderConstants;
use PHPCompiler\ext\xmlreader\VmXmlReader;
use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * XMLReader pull parser registration and traversal (#6135).
 *
 * @group XMLReader
 */
final class XmlReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \PHPCompiler\ext\xmlreader\XmlReaderRegistry::reset();
    }

    public function test_xmlreader_class_and_extension_loaded(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::classExists($ctx, 'XMLReader'));
        self::assertTrue(ModuleRegistry::extensionLoaded('xmlreader'));

        $class = $ctx->classes[VmXmlReader::CLASS_LC];
        self::assertSame(XmlReaderConstants::ELEMENT, $class->constants['ELEMENT']->toInt());
        self::assertSame(XmlReaderConstants::END_ELEMENT, $class->constants['END_ELEMENT']->toInt());
    }

    public function test_xmlreader_open_read_repro(): void
    {
        $runtime = new Runtime();
        $code = (string) file_get_contents(__DIR__.'/../repro/maintainer_gap_xmlreader_open_read.php');
        $block = $runtime->parseAndCompile($code, 'xmlreader_repro.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n", ob_get_clean());
    }

    public function test_xmlreader_open_missing_file_returns_false(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(XMLReader::open('/tmp/xmlreader_missing_'.uniqid().'.xml'));
PHP;
        $block = $runtime->parseAndCompile($code, 'xmlreader_missing.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('false', ob_get_clean());
    }

    public function test_xmlreader_xml_memory_repro(): void
    {
        $runtime = new Runtime();
        $code = (string) file_get_contents(__DIR__.'/../repro/maintainer_gap_xmlreader_xml.php');
        $block = $runtime->parseAndCompile($code, 'xmlreader_xml_repro.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("r:a:\n", ob_get_clean());
    }

    public function test_xmlreader_xml_empty_source_value_error(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
try {
    $r = new XMLReader();
    $r->XML('');
    echo "no-error\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $block = $runtime->parseAndCompile($code, 'xmlreader_xml_empty.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("XMLReader::XML(): Argument #1 (\$source) cannot be empty\n", ob_get_clean());
    }

    public function test_xmlreader_tokenizer_matches_zend_event_shape(): void
    {
        $events = VmXmlReader::tokenize('<root><item id="1">a</item></root>');
        self::assertCount(5, $events);
        self::assertSame(XmlReaderConstants::ELEMENT, $events[0]->nodeType);
        self::assertSame('root', $events[0]->name);
        self::assertSame(XmlReaderConstants::ELEMENT, $events[1]->nodeType);
        self::assertSame('item', $events[1]->name);
        self::assertSame('1', $events[1]->attributes['id']);
        self::assertSame(XmlReaderConstants::TEXT, $events[2]->nodeType);
        self::assertSame('#text', $events[2]->name);
        self::assertSame('a', $events[2]->value);
        self::assertSame(XmlReaderConstants::END_ELEMENT, $events[3]->nodeType);
        self::assertSame('item', $events[3]->name);
        self::assertSame(XmlReaderConstants::END_ELEMENT, $events[4]->nodeType);
        self::assertSame('root', $events[4]->name);
    }
}
