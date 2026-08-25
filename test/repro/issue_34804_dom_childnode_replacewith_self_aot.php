<?php
declare(strict_types=1);
/**
 * #34804 — ChildNode::replaceWith including self must match php-src fragment semantics.
 * Zend: dom_child_replace_with + dom_zvals_to_fragment (parentnode.c).
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$c = $d->documentElement->lastChild;
$c->replaceWith($c);
echo 'self=', $d->saveXML($d->documentElement), ' parent=', ($c->parentNode ? $c->parentNode->nodeName : 'DETACHED'), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/><b/><c/></r>');
$c2 = $d2->documentElement->lastChild;
$c2->replaceWith($c2, 'x');
echo 'self_str=', $d2->saveXML($d2->documentElement), ' parent=', ($c2->parentNode ? $c2->parentNode->nodeName : 'DETACHED'), "\n";

$d3 = new DOMDocument();
$d3->loadXML('<r><a/><b/><c/></r>');
$c3 = $d3->documentElement->lastChild;
$c3->replaceWith('x', $c3);
echo 'str_self=', $d3->saveXML($d3->documentElement), ' parent=', ($c3->parentNode ? $c3->parentNode->nodeName : 'DETACHED'), "\n";

$d4 = new DOMDocument();
$d4->loadXML('<r><a/><b/><c/></r>');
$a4 = $d4->documentElement->firstChild;
$c4 = $d4->documentElement->lastChild;
$c4->replaceWith($c4, $a4);
echo 'self_a=', $d4->saveXML($d4->documentElement), ' parent=', ($c4->parentNode ? $c4->parentNode->nodeName : 'DETACHED'), "\n";

$d5 = new DOMDocument();
$d5->loadXML('<r><a/><b/><c/></r>');
$a5 = $d5->documentElement->firstChild;
$c5 = $d5->documentElement->lastChild;
$c5->replaceWith($a5, $c5);
echo 'a_self=', $d5->saveXML($d5->documentElement), ' parent=', ($c5->parentNode ? $c5->parentNode->nodeName : 'DETACHED'), "\n";

$d6 = new DOMDocument();
$d6->loadXML('<r><a/><b/><c/></r>');
$c6 = $d6->documentElement->lastChild;
$c6->replaceWith();
echo 'empty=', $d6->saveXML($d6->documentElement), "\n";

$d7 = new DOMDocument();
$d7->loadXML('<r><a/><b/></r>');
$n = $d7->documentElement->firstChild;
try {
    $d7->documentElement->insertBefore($n, $n);
    echo "insertBefore_self=no_throw\n";
} catch (Throwable $e) {
    echo 'insertBefore_self=', get_class($e), "\n";
}
