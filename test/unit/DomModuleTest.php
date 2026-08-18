<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\InfoJitHelper;
use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmInfo;
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
        self::assertTrue(VmReflection::classExists($ctx, 'DOMEntityReference'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMEntity'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMNotation'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMAttr'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMNode'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMNodeList'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMNamedNodeMap'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMCharacterData'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMComment'));
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

    /** php-src ext/dom/php_dom.h DOM_API_VERSION — not PHP runtime (#15439). */
    public function test_dom_phpversion_returns_libxml_module_version(): void
    {
        new Runtime();
        self::assertSame('20031129', VmInfo::phpversion('dom'));
        self::assertSame('20031129', InfoJitHelper::phpversion('dom'));
        self::assertSame(CompilerVersion::reportedPhpVersion(), VmInfo::phpversion('pcre'));
        self::assertSame(CompilerVersion::reportedPhpVersion(), InfoJitHelper::phpversion('pcre'));
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

    public function test_dom_document_xml_metadata_properties(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
echo var_export($doc->encoding, true), "\n";
echo $doc->xmlVersion, "\n";
echo (int) $doc->xmlStandalone, "\n";
echo var_export($doc->documentURI, true), "\n";
$doc->encoding = 'UTF-8';
$doc->loadXML('<?xml version="1.0" encoding="ISO-8859-1" standalone="yes"?><root/>');
echo $doc->encoding, "\n";
echo (int) $doc->xmlStandalone, "\n";
echo (int) str_contains($doc->saveXML(), 'encoding="ISO-8859-1"'), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_xml_props.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("NULL\n1.0\n0\nNULL\nISO-8859-1\n1\n1\n", ob_get_clean());
    }

    public function test_dom_xpath_quote_escapes_literals(): void
    {
        if (!CompilerVersion::supportsDomXPathQuote()) {
            self::markTestSkipped('DOMXPath::quote() withheld on 8.2 reference profile (#18650)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo DOMXPath::quote("'quoted' name"), "\n";
echo DOMXPath::quote("'different' \"quote\" styles"), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_xpath_quote.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("\"'quoted' name\"\nconcat(\"'different' \",'".'"quote" styles'."')\n", ob_get_clean());
    }

    public function test_dom_node_contains_descendant_check(): void
    {
        if (!CompilerVersion::supportsDomNodeContains()) {
            self::markTestSkipped('DOMNode::contains() withheld on 8.2 reference profile (#14535)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><parent><child/></parent></root>');
$root = $doc->documentElement;
$child = $root->firstChild->firstChild;
echo (int) $root->contains($child), "\n";
echo (int) $child->contains($root), "\n";
echo (int) $root->contains($root), "\n";
echo (int) $root->contains(null), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_contains.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n0\n1\n0\n", ob_get_clean());
    }

    public function test_dom_node_get_node_path(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><child><leaf/></child></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild->firstChild;
echo $doc->getNodePath(), "\n";
echo $root->getNodePath(), "\n";
echo $leaf->getNodePath(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_get_node_path.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("/\n/root\n/root/child/leaf\n", ob_get_clean());
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

    public function test_dom_implementation_get_feature_registered(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$impl = new DOMImplementation();
echo (int) method_exists($impl, 'getFeature'), "\n";
try {
    $impl->getFeature('Core', '2.0');
    echo "no_throw\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_get_feature.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\nNot yet implemented\n", ob_get_clean());
    }

    public function test_dom_document_adopt_node_nyi_on_reference_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
$d1 = new DOMDocument();
$d1->loadXML('<a><n>t</n></a>');
$d2 = new DOMDocument();
echo (int) method_exists($d2, 'adoptNode'), "\n";
try {
    $d2->adoptNode($d1->documentElement->firstChild);
    echo "adopted\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
PHP;
            $block = $runtime->parseAndCompile($code, 'dom_adopt_node_nyi.php');
            ob_start();
            $runtime->run($block);
            self::assertSame("1\nNot yet implemented\n", ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function test_dom_document_adopt_node_registered(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
$d1 = new DOMDocument();
$d1->loadXML('<a><n>t</n></a>');
$d2 = new DOMDocument();
$d2->loadXML('<b/>');
$n = $d1->documentElement->firstChild;
echo (int) method_exists($d2, 'adoptNode'), "\n";
$a = $d2->adoptNode($n);
echo $a->nodeName, "\n";
echo $d1->saveXML($d1->documentElement), "\n";
echo ($a->ownerDocument === $d2) ? "owner-d2\n" : "owner-other\n";
$d2->documentElement->appendChild($a);
echo $d2->saveXML($d2->documentElement), "\n";
try {
    $d2->adoptNode($d1);
    echo "adopted-doc\n";
} catch (DOMException $e) {
    echo (DOMException::NOT_SUPPORTED_ERR === $e->getCode()) ? "reject-doc\n" : ("other\n");
}
PHP;
            $block = $runtime->parseAndCompile($code, 'dom_adopt_node.php');
            ob_start();
            $runtime->run($block);
            self::assertSame(
                "1\nn\n<a/>\nowner-d2\n<b><n>t</n></b>\nreject-doc\n",
                ob_get_clean()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function test_dom_node_is_supported_and_default_namespace(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$el = $doc->createElement('root');
echo (int) $el->isSupported('Core', '2.0'), "\n";
echo (int) $el->isSupported('Core', '1.0'), "\n";
$doc->loadXML('<root xmlns="http://example.com"/>');
$root = $doc->documentElement;
echo (int) $root->isDefaultNamespace('http://example.com'), "\n";
echo (int) $root->isDefaultNamespace('http://other.example.com'), "\n";
echo (int) $root->isDefaultNamespace(null), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_is_supported.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("0\n1\n1\n0\n0\n", ob_get_clean());
    }

    public function test_dom_element_set_attribute_on_create_element(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$el = $doc->createElement('item');
$el->setAttribute('id', 'x');
echo $el->tagName, "\n";
echo $el->getAttribute('id'), "\n";
echo $el->getAttribute('missing'), "\n";
$doc->appendChild($el);
echo (int) str_contains($doc->saveXML(), 'id="x"'), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_element_set_attribute.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("item\nx\n\n1\n", ob_get_clean());
    }

    public function test_dom_element_set_attribute_empty_name_value_error(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$doc->loadXML('<r/>');
$el = $doc->documentElement;
foreach ([null, ''] as $i => $name) {
    try {
        $el->setAttribute($name, 'x');
        echo "set$i=ok\n";
    } catch (Throwable $e) {
        echo 'set'.$i.'='.get_class($e).':'.$e->getMessage()."\n";
    }
}
try {
    $el->setAttributeNS(null, '', 'x');
    echo "setNS=ok\n";
} catch (Throwable $e) {
    echo 'setNS='.get_class($e).':'.$e->getMessage()."\n";
}
echo 'attrs='.$el->attributes->length."\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_setattr_empty.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "set0=ValueError:DOMElement::setAttribute(): Argument #1 (\$qualifiedName) cannot be empty\n"
            ."set1=ValueError:DOMElement::setAttribute(): Argument #1 (\$qualifiedName) cannot be empty\n"
            ."setNS=ValueError:DOMElement::setAttributeNS(): Argument #2 (\$qualifiedName) cannot be empty\n"
            ."attrs=0\n",
            ob_get_clean()
        );
    }

    public function test_dom_node_has_attributes(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$doc->loadXML('<r a="1"/>');
echo (int) $doc->documentElement->hasAttributes(), "\n";
$doc->loadXML('<r/>');
echo (int) $doc->documentElement->hasAttributes(), "\n";
$doc->loadXML('<r xmlns="http://example.com"/>');
echo (int) $doc->documentElement->hasAttributes(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_has_attributes.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n0\n0\n", ob_get_clean());
    }

    public function test_dom_node_compare_document_position(): void
    {
        if (!CompilerVersion::supportsDomNodeCompareDocumentPosition()) {
            self::markTestSkipped('DOMNode::compareDocumentPosition() withheld when PHP_COMPILER_PROFILE=8.2 (#18092)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><parent><child/></parent><sibling/></root>');
$parent = $doc->getElementsByTagName('parent')->item(0);
$child = $doc->getElementsByTagName('child')->item(0);
$sibling = $doc->getElementsByTagName('sibling')->item(0);
echo $parent->compareDocumentPosition($child), "\n";
echo $child->compareDocumentPosition($parent), "\n";
echo $parent->compareDocumentPosition($sibling), "\n";
echo (int) (($parent->compareDocumentPosition($sibling) & DOMNode::DOCUMENT_POSITION_DISCONNECTED) === 0), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_compare_document_position.php');
        ob_start();
        $runtime->run($block);
        // Zend 8.4: parent→child CONTAINED_BY|FOLLOWING (20); child→parent CONTAINS|PRECEDING (10) (#25878).
        self::assertSame("20\n10\n4\n1\n", ob_get_clean());
    }

    public function test_dom_node_is_equal_node(): void
    {
        if (!CompilerVersion::supportsDomNodeIsEqualNode()) {
            self::markTestSkipped('DOMNode::isEqualNode() withheld on 8.2 reference profile (#15195)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a id="1"/></root>');
$a = $doc->documentElement->firstChild;
$b = $a->cloneNode(true);
echo (int) $a->isEqualNode($b), "\n";
echo (int) $a->isSameNode($b), "\n";
echo (int) $a->isEqualNode(null), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_is_equal_node.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n0\n0\n", ob_get_clean());
    }

    public function test_dom_create_attribute_ns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$attr = @$doc->createAttributeNS('http://example.com', 'ex:foo');
echo var_export($attr, true), "\n";
$doc->loadXML('<root/>');
$attr = $doc->createAttributeNS('http://example.com', 'ex:foo');
echo get_class($attr), "\n";
echo $attr->localName, "\n";
$attr->value = 'x';
echo $attr->value, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_create_attribute_ns.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("false\nDOMAttr\nfoo\nx\n", ob_get_clean());
    }

    public function test_dom_create_entity_reference(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$ref = $doc->createEntityReference('amp');
echo get_class($ref), "\n";
echo $ref->nodeType, "\n";
echo $ref->nodeName, "\n";
echo var_export($ref->nodeValue, true), "\n";
echo (int) ($ref->ownerDocument === $doc), "\n";
$root = $doc->createElement('root');
$root->appendChild($ref);
echo $root->firstChild->nodeName, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_create_entity_reference.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("DOMEntityReference\n5\namp\nNULL\n1\namp\n", ob_get_clean());
    }

    public function test_dom_document_fragment_append_xml(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$frag = $doc->createDocumentFragment();
$ok = $frag->appendXML('<a/><b/>');
echo (int) $ok, "\n";
echo $frag->childNodes->length, "\n";
echo $frag->firstChild->nodeName, "\n";
echo $frag->lastChild->nodeName, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_fragment_append_xml.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n2\na\nb\n", ob_get_clean());
    }

    public function test_dom_save_html_file(): void
    {
        $runtime = new Runtime();
        $path = sys_get_temp_dir().'/dom_module_savehtmlfile_test.html';
        $code = <<<PHP
<?php
\$d = new DOMDocument();
\$d->loadHTML('<p>hi</p>');
\$bytes = \$d->saveHTMLFile('{$path}');
echo \$bytes, "\\n";
echo (int) str_contains((string) file_get_contents('{$path}'), '<p>hi</p>'), "\\n";
@unlink('{$path}');
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_save_html_file.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertMatchesRegularExpression('/^\d+\n1\n$/', $out);
        self::assertGreaterThan(0, (int) explode("\n", $out)[0]);
    }

    public function test_dom_document_load(): void
    {
        $runtime = new Runtime();
        $path = sys_get_temp_dir().'/dom_module_document_load_test.xml';
        file_put_contents($path, '<root><child/></root>');
        $code = <<<PHP
<?php
\$d = new DOMDocument();
echo (int) \$d->load('{$path}'), "\n";
echo \$d->documentElement->firstChild->nodeName, "\n";
echo (int) \$d->load('{$path}.missing'), "\n";
@unlink('{$path}');
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_document_load.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\nchild\n0\n", ob_get_clean());
    }

    public function test_dom_text_split_text(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$text = $doc->createTextNode('hello');
$root->appendChild($text);
$tail = $text->splitText(2);
echo $text->data, "\n";
echo $tail->data, "\n";
echo ($tail->previousSibling === $text) ? "prev\n" : "noprev\n";
echo ($text->nextSibling === $tail) ? "next\n" : "nonext\n";
echo $doc->saveXML($root), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_text_split_text.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("he\nllo\nprev\nnext\n<root>hello</root>\n", ob_get_clean());
    }

    public function test_dom_text_split_text_offset_validation(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$text = $doc->createTextNode('ab');
try {
    $text->splitText(-1);
    echo "noexception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
var_export($doc->createTextNode('a')->splitText(5));
echo "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_text_split_text_offset.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "DOMText::splitText(): Argument #1 (\$offset) must be greater than or equal to 0\nfalse\n",
            ob_get_clean()
        );
    }

    public function test_dom_text_whitespace_methods(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$ws = $doc->createTextNode('  ');
$root->appendChild($ws);
echo (int) method_exists($ws, 'isElementContentWhitespace'), "\n";
echo (int) method_exists($ws, 'isWhitespaceInElementContent'), "\n";
echo (int) $ws->isWhitespaceInElementContent(), "\n";
echo (int) $ws->isElementContentWhitespace(), "\n";
$nonWs = $doc->createTextNode('x');
$root->appendChild($nonWs);
echo (int) $nonWs->isWhitespaceInElementContent(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_text_whitespace_methods.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n1\n1\n1\n0\n", ob_get_clean());
    }

    public function test_dom_comment_tree_mutation(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$comment = $doc->createComment('note');
$doc->appendChild($comment);
echo get_class($doc->firstChild), "\n";
echo null === $doc->documentElement ? "null\n" : "set\n";
$root = $doc->createElement('r');
$doc->appendChild($root);
$child = $doc->createElement('c');
$root->appendChild($child);
$root->insertBefore($doc->createComment('note'), $child);
echo $doc->saveXML($root), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_comment_tree.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("DOMComment\nnull\n<r><!--note--><c/></r>\n", ob_get_clean());
    }

    public function test_dom_xpath_query_attribute_predicate(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><item id="1">a</item><item id="2">b</item></root>');
echo (int) class_exists('DOMXPath', false), "\n";
$xpath = new DOMXPath($doc);
$nodes = $xpath->query('//item[@id="2"]');
echo $nodes->length, "\n";
echo $nodes->item(0)->textContent, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_xpath_query.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n1\nb\n", ob_get_clean());
    }

    /** @see https://github.com/PurHur/php-compiler/issues/22008 */
    public function test_dom_xpath_query_text_predicates(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a>hello</a></r>');
$xpath = new DOMXPath($doc);
echo $xpath->query("//a[text()='hello']")->length, "\n";
echo $xpath->query("//a[contains(text(),'ell')]")->length, "\n";
echo $xpath->query('//a')->length, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_xpath_text_pred.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n1\n1\n", ob_get_clean());
    }

    public function test_dom_html_document_create_from_string_living_namespace(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsDomLivingStandardNamespace()) {
                self::markTestSkipped('Dom\\ living-standard namespace withheld without PHP_COMPILER_PROFILE=8.4 (#6506)');
            }
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
echo (int) class_exists('Dom\\HTMLDocument'), "\n";
$doc = Dom\HTMLDocument::createFromString('<p>hi</p>');
echo $doc->body->textContent, "\n";
PHP;
            $block = $runtime->parseAndCompile($code, 'dom_html_document.php');
            ob_start();
            $runtime->run($block);
            self::assertSame("1\nhi\n", ob_get_clean());
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$previous);
            }
        }
    }

    public function test_dom_xml_document_create_from_string_living_namespace(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsDomLivingStandardNamespace()) {
                self::markTestSkipped('Dom\\ living-standard namespace withheld without PHP_COMPILER_PROFILE=8.4 (#19581)');
            }
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
echo (int) method_exists(Dom\XMLDocument::class, 'createFromString'), "\n";
$doc = Dom\XMLDocument::createFromString('<?xml version="1.0"?><root><a/></root>');
echo $doc->documentElement->nodeName, "\n";
$empty = Dom\XMLDocument::createEmpty();
echo ($empty->documentElement === null ? 'NULL' : 'set'), "\n";
PHP;
            $block = $runtime->parseAndCompile($code, 'dom_xml_document.php');
            ob_start();
            $runtime->run($block);
            self::assertSame("1\nroot\nNULL\n", ob_get_clean());
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$previous);
            }
        }
    }

    /**
     * php-src php_dom.stub.php — Dom\Element attribute getters expose nullable returns (#26065).
     */
    public function test_dom_element_getattr_reflection_return_types(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsDomLivingStandardNamespace()) {
                self::markTestSkipped('Dom\\ living-standard namespace withheld without PHP_COMPILER_PROFILE=8.4 (#26065)');
            }
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
$d = Dom\HTMLDocument::createEmpty();
$el = $d->createElement('div');
foreach (['getAttribute', 'getAttributeNS', 'getAttributeNode', 'getAttributeNodeNS'] as $m) {
    $rm = new ReflectionMethod($el, $m);
    $t = $rm->getReturnType();
    echo $m, '=', $t ? $t->__toString() : '(none)', "\n";
}
PHP;
            $block = $runtime->parseAndCompile($code, 'dom_element_getattr_reflection.php');
            ob_start();
            $runtime->run($block);
            self::assertSame(
                "getAttribute=?string\n"
                ."getAttributeNS=?string\n"
                ."getAttributeNode=?Dom\\Attr\n"
                ."getAttributeNodeNS=?Dom\\Attr\n",
                ob_get_clean()
            );
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$previous);
            }
        }
    }

    /**
     * CSS child/sibling combinators on Dom\ ParentNode (#32061, php-src parentnode.c / lexbor).
     */
    public function test_dom_parent_node_css_combinators(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsDomLivingStandardNamespace()) {
                self::markTestSkipped('Dom\\ living-standard namespace withheld without PHP_COMPILER_PROFILE=8.4 (#32061)');
            }
            $runtime = new Runtime();
            $code = file_get_contents(__DIR__.'/../repro/issue_32061_dom_qsa_combinators.php');
            self::assertNotFalse($code);
            $block = $runtime->parseAndCompile($code, 'dom_qsa_combinators.php');
            ob_start();
            $runtime->run($block);
            self::assertSame(
                "child=p\n"
                ."compact=p\n"
                ."nested=e\n"
                ."qsa_child=1\n"
                ."adj=s\n"
                ."gen=p2\n"
                ."body_p=p2\n"
                ."desc=p\n"
                ."matches_child=yes\n"
                ."matches_nested=no\n"
                ."matches_adj=yes\n"
                ."closest=s\n"
                ."first=p\n"
                ."bad[div >]=SyntaxError\n"
                ."bad[> p]=SyntaxError\n"
                ."bad[div > > p]=SyntaxError\n"
                ."bad[p++span]=SyntaxError\n",
                ob_get_clean()
            );
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$previous);
            }
        }
    }

    /**
     * CSS attribute selectors on Dom\ ParentNode (#32089, php-src parentnode.c / lexbor).
     */
    public function test_dom_parent_node_css_attribute_selectors(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsDomLivingStandardNamespace()) {
                self::markTestSkipped('Dom\\ living-standard namespace withheld without PHP_COMPILER_PROFILE=8.4 (#32089)');
            }
            $runtime = new Runtime();
            $code = file_get_contents(__DIR__.'/../repro/issue_32089_dom_qsa_attr.php');
            self::assertNotFalse($code);
            $block = $runtime->parseAndCompile($code, 'dom_qsa_attr.php');
            ob_start();
            $runtime->run($block);
            self::assertSame(
                "[hidden]=s\n"
                ."[id=\"p\"]=p\n"
                ."[id=p]=p\n"
                ."[class~=\"y\"]=p\n"
                ."[id^=\"p\"]=p\n"
                ."[id$=\"2\"]=p2\n"
                ."[id*=\"p\"]=p\n"
                ."[lang|=\"en\"]=p2\n"
                ."span[hidden]=s\n"
                ."div[class=\"box\"]=d\n"
                ."[data-x=\"1\"]=d\n"
                ."p[class~=\"x\"]=p\n"
                ."div > [hidden]=s\n"
                ."p[id] + span=s\n"
                ."[id=\"P\" i]=p\n"
                ."[class=\"x y\"]=p\n"
                ."div[id]=d\n"
                ."qsa_id=4\n"
                ."matches_hidden=yes\n"
                ."matches_p_hidden=no\n"
                ."closest=d\n"
                ."bad[[]=SyntaxError\n"
                ."bad[[]]=SyntaxError\n"
                ."bad[[=x]]=SyntaxError\n"
                ."bad[[attr=]]=SyntaxError\n",
                ob_get_clean()
            );
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$previous);
            }
        }
    }

    /**
     * CSS :empty / :only-child / :only-of-type / :root on Dom\ ParentNode
     * (#32132, php-src parentnode.c / lexbor).
     */
    public function test_dom_parent_node_css_empty_only_root(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsDomLivingStandardNamespace()) {
                self::markTestSkipped('Dom\\ living-standard namespace withheld without PHP_COMPILER_PROFILE=8.4 (#32132)');
            }
            $runtime = new Runtime();
            $code = file_get_contents(__DIR__.'/../repro/issue_dom_qsa_empty_only_root.php');
            self::assertNotFalse($code);
            $block = $runtime->parseAndCompile($code, 'dom_qsa_empty_only_root.php');
            ob_start();
            $runtime->run($block);
            self::assertSame(
                ":root=container [container]\n"
                ."container:root=container [container]\n"
                ."p:root=null []\n"
                ."p:only-child=lonely [lonely,textsib]\n"
                ."p:only-of-type=lonely [lonely,textsib,mixp]\n"
                ."span:only-of-type=mixs [mixs]\n"
                ."e:empty=e0 [e0,e1,e3,e4]\n"
                ."g3 p:only-child=textsib [textsib]\n"
                ."g3 p:first-child=textsib [textsib]\n"
                ."matches_lonely_only=yes\n"
                ."matches_a_only=no\n"
                ."matches_textsib_only=yes\n"
                ."matches_mixp_only_type=yes\n"
                ."matches_e0_empty=yes\n"
                ."matches_e2_empty=no\n"
                ."matches_e3_empty=yes\n"
                ."matches_e5_empty=no\n"
                ."matches_e6_empty=no\n"
                ."matches_container_root=yes\n"
                ."matches_g1_root=no\n"
                ."closest=container\n"
                ."frag_root=froot\n"
                ."frag_p_root=no\n"
                ."loose_qsa=null\n"
                ."loose_matches=no\n"
                ."loose_empty=yes\n"
                ."bad[:empty()]=SyntaxError\n"
                ."bad[:only-child()]=SyntaxError\n"
                ."bad[:root()]=SyntaxError\n"
                ."bad[p:blank]=SyntaxError\n",
                ob_get_clean()
            );
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$previous);
            }
        }
    }

    public function test_runtime_shrink_has_no_dom_c_runtime(): void
    {
        $linker = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        self::assertStringNotContainsString('phpc_dom', $linker);
        self::assertStringNotContainsString('dom_', $linker);
    }
}
