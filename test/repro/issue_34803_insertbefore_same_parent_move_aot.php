<?php
declare(strict_types=1);
/**
 * #34803 — same-parent insertBefore / ChildNode::before must unlink then splice (php-src node.c).
 * AOT previously cycled sibling links → hang / exit 137.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><c/></r>');
$r = $doc->documentElement;
$a = $r->firstChild;
$c = $a->nextSibling;
$r->insertBefore($c, $a);
echo 'insertBefore_move=', $doc->saveXML($r), "\n";

$doc2 = new DOMDocument();
$doc2->loadXML('<r><a/><c/></r>');
$a2 = $doc2->documentElement->firstChild;
$c2 = $a2->nextSibling;
$a2->before($c2);
echo 'before_next=', $doc2->saveXML($doc2->documentElement), "\n";

$doc3 = new DOMDocument();
$doc3->loadXML('<r><a/><b/><c/></r>');
$a3 = $doc3->documentElement->firstChild;
$c3 = $doc3->documentElement->lastChild;
$a3->before($c3);
echo 'before_last=', $doc3->saveXML($doc3->documentElement), "\n";

// Already immediately before → no-op
$doc4 = new DOMDocument();
$doc4->loadXML('<r><c/><a/></r>');
$a4 = $doc4->documentElement->lastChild;
$c4 = $doc4->documentElement->firstChild;
$a4->before($c4);
echo 'before_already=', $doc4->saveXML($doc4->documentElement), "\n";

// Fresh string insert still works
$doc5 = new DOMDocument();
$doc5->loadXML('<r><a/><c/></r>');
$doc5->documentElement->firstChild->before('x');
echo 'before_str=', $doc5->saveXML($doc5->documentElement), "\n";

// insertBefore identity must still throw (#34709)
$doc6 = new DOMDocument();
$doc6->loadXML('<r><a/><b/></r>');
$n = $doc6->documentElement->firstChild;
try {
    $doc6->documentElement->insertBefore($n, $n);
    echo "insertBefore_self=no_throw\n";
} catch (Throwable $e) {
    echo 'insertBefore_self=', get_class($e), "\n";
}
