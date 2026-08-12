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
        self::assertSame(XmlReaderConstants::ELEMENT, $class->constants['element']->toInt());
        self::assertSame(XmlReaderConstants::END_ELEMENT, $class->constants['end_element']->toInt());
        self::assertSame('ELEMENT', $class->constNames['element']);
        self::assertSame('END_ELEMENT', $class->constNames['end_element']);
    }

    /** @covers \PHPCompiler\ext\xmlreader\XmlReaderConstants::registerOnClassEntry */
    public function test_xmlreader_class_constants_discovery_apis(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo defined('XMLReader::ELEMENT') ? 'Y' : 'N', "\n";
echo constant('XMLReader::ELEMENT'), "\n";
echo (new ReflectionClass('XMLReader'))->getConstant('ELEMENT'), "\n";
echo XMLReader::ELEMENT, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'xmlreader_const_discovery.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("Y\n1\n1\n1\n", ob_get_clean());
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

    public function test_xmlreader_xml_open_instance_bool_repro(): void
    {
        $runtime = new Runtime();
        $code = (string) file_get_contents(__DIR__.'/../repro/maintainer_xmlreader_xml_open.php');
        $block = $runtime->parseAndCompile($code, 'xmlreader_xml_open_bool_repro.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "true\nroot=1\ntrue\nroot=2\ntrue\nstatic=a=s\n",
            ob_get_clean()
        );
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

    /** @covers \PHPCompiler\ext\xmlreader\XmlReaderOpen */
    public function test_xmlreader_open_empty_uri_value_error(): void
    {
        $runtime = new Runtime();
        $code = (string) file_get_contents(__DIR__.'/../repro/xmlreader_open_empty_uri_message_parity.php');
        $block = $runtime->parseAndCompile($code, 'xmlreader_open_empty_uri.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "ValueError:XMLReader::open(): Argument #1 (\$uri) cannot be empty\n"
            ."ValueError:XMLReader::XML(): Argument #1 (\$source) cannot be empty\n"
            ."ValueError:XMLReader::open(): Argument #1 (\$uri) cannot be empty\n",
            ob_get_clean()
        );
    }

    /** @covers \PHPCompiler\ext\xmlreader\XmlReaderXML */
    /** @covers \PHPCompiler\ext\xmlreader\XmlReaderOpen */
    public function test_xmlreader_null_source_deprecated_then_value_error(): void
    {
        $runtime = new Runtime();
        $code = (string) file_get_contents(__DIR__.'/../repro/issue_30563_xmlreader_null_weak.php');
        $block = $runtime->parseAndCompile($code, 'xmlreader_null_weak.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "DEP:XMLReader::XML(): Passing null to parameter #1 (\$source) of type string is deprecated\n"
            ."ValueError:XMLReader::XML(): Argument #1 (\$source) cannot be empty\n"
            ."DEP:XMLReader::open(): Passing null to parameter #1 (\$uri) of type string is deprecated\n"
            ."ValueError:XMLReader::open(): Argument #1 (\$uri) cannot be empty\n",
            ob_get_clean()
        );
    }

    /** @covers \PHPCompiler\ext\xmlreader\XmlReaderXML */
    /** @covers \PHPCompiler\ext\xmlreader\XmlReaderOpen */
    public function test_xmlreader_null_source_strict_type_error(): void
    {
        $runtime = new Runtime();
        $code = (string) file_get_contents(__DIR__.'/../repro/issue_30563_xmlreader_null_strict.php');
        $block = $runtime->parseAndCompile($code, 'xmlreader_null_strict.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "TypeError:XMLReader::XML(): Argument #1 (\$source) must be of type string, null given\n"
            ."TypeError:XMLReader::open(): Argument #1 (\$uri) must be of type string, null given\n",
            ob_get_clean()
        );
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

    public function test_xmlreader_get_attribute_ns_no_repro(): void
    {
        $runtime = new Runtime();
        $code = (string) file_get_contents(__DIR__.'/../repro/maintainer_gap_xmlreader_get_attribute_ns_no.php');
        $block = $runtime->parseAndCompile($code, 'xmlreader_get_attribute_ns_no_repro.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "a='1'\n"
            ."ns='2'\n"
            ."no0='urn:x'\n"
            ."no1='2'\n"
            ."no2='1'\n"
            ."missing=NULL\n"
            ."xmlnsDecl='urn:x'\n"
            ."oob=NULL\n"
            ."emptyName=XMLReader::getAttributeNs(): Argument #1 (\$name) cannot be empty\n"
            ."emptyNs=XMLReader::getAttributeNs(): Argument #2 (\$namespace) cannot be empty\n",
            ob_get_clean()
        );
    }

    public function test_xmlreader_read_inner_outer_string_repro(): void
    {
        $runtime = new Runtime();
        $code = (string) file_get_contents(__DIR__.'/../repro/maintainer_gap_xmlreader_read_inner_outer_string.php');
        $block = $runtime->parseAndCompile($code, 'xmlreader_read_inner_outer_string_repro.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "inner=<c>t</c>\nouter=<root><c>t</c></root>\nstr=t\n",
            ob_get_clean()
        );
    }

    public function test_xmlreader_malformed_read_warnings_repro(): void
    {
        $runtime = new Runtime();
        $code = (string) file_get_contents(__DIR__.'/../repro/maintainer_gap_xmlreader_malformed_read_warnings.php');
        $block = $runtime->parseAndCompile($code, 'xmlreader_malformed_read_warnings_repro.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("parser error : Extra content at the end of the document\n", $out);
        self::assertStringContainsString("XMLReader::read(): <not-closed>\n", $out);
        self::assertMatchesRegularExpression('/XMLReader::read\(\): +\\^\n/', $out);
        self::assertStringStartsWith("3\n", $out);
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
