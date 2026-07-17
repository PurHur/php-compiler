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

    public function test_xmlreader_open_instance_repro(): void
    {
        $runtime = new Runtime();
        $code = (string) file_get_contents(__DIR__.'/../repro/maintainer_gap_xmlreader_open_instance.php');
        $block = $runtime->parseAndCompile($code, 'xmlreader_open_instance_repro.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\nroot,a\n", ob_get_clean());
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

    public function test_xmlreader_from_factories_withheld_on_reference_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE'], $_SERVER['PHP_COMPILER_PROFILE']);
        try {
            $runtime = new Runtime();
            $class = $runtime->vmContext->classes[VmXmlReader::CLASS_LC];
            self::assertArrayNotHasKey('fromstring', $class->methods);
            self::assertArrayNotHasKey('fromuri', $class->methods);
            self::assertArrayNotHasKey('fromstream', $class->methods);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE'], $_SERVER['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
                $_SERVER['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }

    public function test_xmlreader_from_factories_under_84_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $_SERVER['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $runtime = new Runtime();
            $code = (string) file_get_contents(__DIR__.'/../repro/maintainer_gap_xmlreader_from_factories.php');
            $block = $runtime->parseAndCompile($code, 'xmlreader_from_factories.php');
            ob_start();
            $runtime->run($block);
            self::assertSame("read=1 name=root\n", ob_get_clean());
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE'], $_SERVER['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
                $_SERVER['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }

    public function test_xmlreader_move_to_attribute_next_repro(): void
    {
        $runtime = new Runtime();
        $code = (string) file_get_contents(__DIR__.'/../repro/maintainer_gap_xmlreader_move_next.php');
        $block = $runtime->parseAndCompile($code, 'xmlreader_move_next_repro.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "name=a attrCount=2\ntrue\nattrName=id val=1 type=2\ntrue\ntrue\nfirst=id\ntrue\nnextAttr=x\ntrue\nafterNext=b\n",
            ob_get_clean()
        );
    }

    public function test_xmlreader_lookup_namespace_repro(): void
    {
        $runtime = new Runtime();
        $code = (string) file_get_contents(__DIR__.'/../repro/maintainer_gap_xmlreader_lookup_namespace.php');
        $block = $runtime->parseAndCompile($code, 'xmlreader_lookup_namespace_repro.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "lookupNs='urn:x'\nunknown=NULL\nonC_p='urn:x'\nonC_q='urn:q'\n",
            ob_get_clean()
        );
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
