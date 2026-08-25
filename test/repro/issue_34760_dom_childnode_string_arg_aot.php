<?php
/**
 * #34760 — AOT: ChildNode after/before/replaceWith(string) must insert text like Zend.
 * php-src: ext/dom/childnode.c — string arms → text nodes.
 */
$d1 = new DOMDocument();
$d1->loadXML('<r><a/></r>');
$a = $d1->documentElement->firstChild;
$a->after('x');
echo 'after_assigned=', $d1->saveXML($d1->documentElement), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/></r>');
$d2->documentElement->firstChild->after('y');
echo 'after_chained=', $d2->saveXML($d2->documentElement), "\n";

$d3 = new DOMDocument();
$d3->loadXML('<r><a/></r>');
$d3->documentElement->firstChild->before('b');
echo 'before=', $d3->saveXML($d3->documentElement), "\n";

$d4 = new DOMDocument();
$d4->loadXML('<r><a/></r>');
$d4->documentElement->firstChild->replaceWith('z');
echo 'replaceWith=', $d4->saveXML($d4->documentElement), "\n";
