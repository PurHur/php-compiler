<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * dom extension module registration (issue #6140).
 *
 * @group dom_module
 */
final class DomModuleTest extends TestCase
{
    public function test_dom_module_registers_implementation(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::classExists($ctx, 'DOMImplementation'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMDocument'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMDocumentType'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMElement'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMNode'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMNodeList'));
        self::assertTrue(ModuleRegistry::extensionLoaded('dom'));

        $code = <<<'PHP'
<?php
echo (int) class_exists('DOMImplementation', false);
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('1', ob_get_clean());
    }

    public function test_dom_node_is_same_node_identity(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$a = $doc->getElementsByTagName('a')->item(0);
$b = $doc->getElementsByTagName('b')->item(0);
echo (int) $a->isSameNode($a), "\n";
echo (int) $a->isSameNode($b), "\n";
$doc2 = new DOMDocument();
$doc2->loadXML('<root><a/></root>');
$a2 = $doc2->getElementsByTagName('a')->item(0);
echo (int) $a->isSameNode($a2), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_is_same_node.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n0\n0\n", ob_get_clean());
    }

    public function test_dom_node_has_child_nodes(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/></root>');
$root = $doc->documentElement;
$leaf = $doc->createElement('leaf');
echo (int) $root->hasChildNodes(), "\n";
echo (int) $leaf->hasChildNodes(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_has_child_nodes.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n0\n", ob_get_clean());
    }

    public function test_dom_node_introspection_properties(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/></root>');
$root = $doc->documentElement;
echo $root->nodeType, "\n";
echo ($root->ownerDocument === $doc) ? "doc\n" : "other\n";
echo var_export($root->nodeValue, true), "\n";
$root->nodeValue = 'hello';
echo var_export($root->nodeValue, true), "\n";
echo $root->childNodes->length, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_introspection.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\ndoc\n''\n'hello'\n1\n", ob_get_clean());
    }

    public function test_dom_node_text_content_and_previous_sibling(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$a = $doc->getElementsByTagName('a')->item(0);
$b = $doc->getElementsByTagName('b')->item(0);
$root = $doc->documentElement;
echo ($b->previousSibling === $a) ? "prev\n" : "noprev\n";
echo null === $a->previousSibling ? "nullprev\n" : "badprev\n";
$root->textContent = 'hi';
echo var_export($root->textContent, true), "\n";
echo $root->childNodes->length, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_text_content.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("prev\nnullprev\n'hi'\n1\n", ob_get_clean());
    }

    public function test_dom_document_savexml_node_subtree(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a id="1"/><b/></root>');
$a = $doc->getElementsByTagName('a')->item(0);
echo $doc->saveXML($a), "\n";
echo str_contains($doc->saveXML(), 'id="1"') ? "attrs\n" : "noattrs\n";
echo $doc->saveXML() === $doc->saveXML(null) ? "null\n" : "nonull\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_savexml_node.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("<a id=\"1\"/>\nattrs\nnull\n", ob_get_clean());
    }

    public function test_runtime_shrink_has_no_dom_c_runtime(): void
    {
        $linker = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        self::assertStringNotContainsString('phpc_dom', $linker);
        self::assertStringNotContainsString('dom_', $linker);
    }
}
