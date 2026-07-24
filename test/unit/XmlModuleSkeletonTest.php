<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * xml extension module skeleton registration (issue #7406).
 *
 * @group xml_parser_skeleton
 */
final class XmlModuleSkeletonTest extends TestCase
{
    public function test_xml_module_skeleton_functions_and_extension_loaded(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['xml_parser_create', 'xml_parser_create_ns', 'xml_parse'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('xml_parser_create');
echo (int) function_exists('xml_parser_create_ns');
echo (int) function_exists('xml_parse');
echo (int) extension_loaded('xml');
PHP;
        $block = $runtime->parseAndCompile($code, 'xml_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('1111', ob_get_clean());
    }

    public function test_xml_parser_create_ns_expands_namespaced_elements(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function ns_start($parser, $name, $attrs) { echo "S:$name\n"; }
function ns_end($parser, $name) { echo "E:$name\n"; }
$p = xml_parser_create_ns();
xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 0);
xml_set_element_handler($p, 'ns_start', 'ns_end');
xml_parse($p, '<r xmlns:a="urn:a"><a:x/></r>', true);
PHP;
        $block = $runtime->parseAndCompile($code, 'xml_parser_create_ns.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("S:r\nS:urn:a:x\nE:urn:a:x\nE:r\n", ob_get_clean());
    }

    public function test_xml_parser_create_returns_xmlparser_object(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$p = xml_parser_create();
echo is_object($p) ? get_class($p) : gettype($p);
PHP;
        $block = $runtime->parseAndCompile($code, 'xml_parser_create.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('XMLParser', ob_get_clean());
    }

    public function test_xml_set_element_handler_callbacks(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function xs($parser, $name, $attrs) { echo "start:$name\n"; }
function xe($parser, $name) { echo "end:$name\n"; }
$p = xml_parser_create();
xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 0);
xml_set_element_handler($p, 'xs', 'xe');
xml_parse($p, '<root><a/></root>', true);
xml_parser_free($p);
echo "done\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'xml_set_element_handler.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("start:root\nstart:a\nend:a\nend:root\ndone\n", ob_get_clean());
    }

    /** php-src ext/xml/xml.c — xml_parser_free is a no-op since PHP 8.0 (#22813). */
    public function test_xml_parser_free_is_noop(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(__DIR__.'/../repro/xml_parser_free_noop.php');
        self::assertNotFalse($code);
        $block = $runtime->parseAndCompile($code, 'xml_parser_free_noop.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "parse_ret=1 type=XMLParser\ntrue\n",
            ob_get_clean()
        );
    }
}
