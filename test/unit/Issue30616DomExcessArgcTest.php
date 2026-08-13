<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for DOM instance methods (#30616).
 *
 * php-src: ext/dom/node.c / document.c / xpath.c / php_dom.stub.php
 */
final class Issue30616DomExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_dom_excess_argc_30616.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_dom_excess_argc_30616.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "DOMNode::appendChild() expects exactly 1 argument, 2 given\n"
            ."DOMNode::removeChild() expects exactly 1 argument, 2 given\n"
            ."DOMNode::cloneNode() expects at most 1 argument, 2 given\n"
            ."DOMNode::hasChildNodes() expects exactly 0 arguments, 1 given\n"
            ."DOMNode::normalize() expects exactly 0 arguments, 1 given\n"
            ."DOMNode::isSameNode() expects exactly 1 argument, 2 given\n"
            ."DOMDocument::getElementById() expects exactly 1 argument, 2 given\n"
            ."DOMDocument::createElement() expects at most 2 arguments, 3 given\n"
            ."DOMDocument::createTextNode() expects exactly 1 argument, 2 given\n"
            ."DOMDocument::createAttribute() expects exactly 1 argument, 2 given\n"
            ."DOMDocument::createComment() expects exactly 1 argument, 2 given\n"
            ."DOMDocument::getElementsByTagName() expects exactly 1 argument, 2 given\n"
            ."DOMDocument::loadXML() expects at most 2 arguments, 3 given\n"
            ."DOMDocument::saveXML() expects at most 2 arguments, 3 given\n"
            ."DOMDocument::saveHTML() expects at most 1 argument, 2 given\n"
            ."DOMDocument::xinclude() expects at most 1 argument, 2 given\n"
            ."DOMDocument::validate() expects exactly 0 arguments, 1 given\n"
            ."DOMElement::setAttribute() expects exactly 2 arguments, 3 given\n"
            ."DOMElement::getAttribute() expects exactly 1 argument, 2 given\n"
            ."DOMElement::hasAttribute() expects exactly 1 argument, 2 given\n"
            ."DOMElement::removeAttribute() expects exactly 1 argument, 2 given\n"
            ."DOMDocument::importNode() expects at most 2 arguments, 3 given\n"
            ."DOMNode::insertBefore() expects at most 2 arguments, 3 given\n"
            ."DOMNode::replaceChild() expects exactly 2 arguments, 3 given\n"
            ."DOMXPath::query() expects at most 3 arguments, 4 given\n"
            ."DOMXPath::evaluate() expects at most 3 arguments, 4 given\n"
            ."DOMXPath::registerNamespace() expects exactly 2 arguments, 3 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('NO_THROW', $out);
    }
}
