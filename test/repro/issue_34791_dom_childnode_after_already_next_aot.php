<?php
declare(strict_types=1);
/**
 * #34791 — ChildNode::after already-next sibling must not throw (php-src parentnode.c).
 * Zend: viable_next_sibling skips nodes in the insertion set.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><c/></r>');
$a = $doc->documentElement->firstChild;
$c = $doc->documentElement->lastChild;
$a->after($c);
echo 'after_same=', $doc->saveXML($doc->documentElement), "\n";

$doc2 = new DOMDocument();
$doc2->loadXML('<r><a/><c/></r>');
$a2 = $doc2->documentElement->firstChild;
$c2 = $doc2->documentElement->lastChild;
$a2->after('x', $c2);
echo 'after_str_node=', $doc2->saveXML($doc2->documentElement), "\n";

$doc3 = new DOMDocument();
$doc3->loadXML('<r><a/><c/></r>');
$a3 = $doc3->documentElement->firstChild;
$c3 = $doc3->documentElement->lastChild;
$a3->after($c3, 'x');
echo 'after_node_str=', $doc3->saveXML($doc3->documentElement), "\n";

// insertBefore identity must still throw (#22686 / #34709)
$doc4 = new DOMDocument();
$doc4->loadXML('<r><a/><b/></r>');
$n = $doc4->documentElement->firstChild;
try {
    $doc4->documentElement->insertBefore($n, $n);
    echo "insertBefore_self=no_throw\n";
} catch (Throwable $e) {
    echo 'insertBefore_self=', get_class($e), "\n";
}
