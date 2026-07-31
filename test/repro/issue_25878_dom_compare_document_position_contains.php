<?php
// #25878 — DOMNode::compareDocumentPosition ancestor/descendant flags vs Zend 8.4
// Expect: a_vs_b=20 (CONTAINED_BY|FOLLOWING), b_vs_a=10 (CONTAINS|PRECEDING), siblings 4/2
$d = new DOMDocument();
$d->loadXML('<r><a><b/></a><c/></r>');
$r = $d->documentElement;
$a = $r->firstChild;
$b = $a->firstChild;
$c = $a->nextSibling;
echo 'a_vs_b=', $a->compareDocumentPosition($b), "\n";
echo 'b_vs_a=', $b->compareDocumentPosition($a), "\n";
echo 'a_vs_c=', $a->compareDocumentPosition($c), "\n";
echo 'c_vs_a=', $c->compareDocumentPosition($a), "\n";
echo 'self=', $a->compareDocumentPosition($a), "\n";
echo 'contains=', (int) $a->contains($b), "\n";
