<?php
declare(strict_types=1);
/**
 * #34813 — ParentNode::prepend(already-first) must no-op like Zend (ext/dom/parentnode.c).
 * insertBefore($n,$n) must still throw (#34709).
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/></r>');
$r = $doc->documentElement;
$a = $r->firstChild;
$r->prepend($a);
echo 'prepend_same=', $doc->saveXML($r), "\n";

$doc2 = new DOMDocument();
$doc2->loadXML('<r><a/><b/></r>');
$r2 = $doc2->documentElement;
$a2 = $r2->firstChild;
$b2 = $r2->lastChild;
$r2->prepend($a2, $b2);
echo 'prepend_ab=', $doc2->saveXML($r2), "\n";

$doc3 = new DOMDocument();
$doc3->loadXML('<r><a/><b/></r>');
$r3 = $doc3->documentElement;
$b3 = $r3->lastChild;
$r3->prepend($b3);
echo 'prepend_move=', $doc3->saveXML($r3), "\n";

$doc4 = new DOMDocument();
$doc4->loadXML('<r><a/><b/><c/></r>');
$r4 = $doc4->documentElement;
$c4 = $r4->lastChild;
$r4->prepend($c4, $r4->firstChild);
echo 'prepend_reorder=', $doc4->saveXML($r4), "\n";

$doc5 = new DOMDocument();
$doc5->loadXML('<r><a/><b/></r>');
$n = $doc5->documentElement->firstChild;
try {
    $doc5->documentElement->insertBefore($n, $n);
    echo "insertBefore_self=no_throw\n";
} catch (Throwable $e) {
    echo 'insertBefore_self=', get_class($e), "\n";
}
