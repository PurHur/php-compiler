<?php

declare(strict_types=1);

/**
 * AOT: cross-parent insertBefore / replaceChild must detach (#33450).
 * php-src: ext/dom/node.c dom_node_insert_before / dom_node_replace_child
 */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$p1 = $r->appendChild($d->createElement('p1'));
$p2 = $r->appendChild($d->createElement('p2'));
$anchor = $p2->appendChild($d->createElement('z'));
$n = $p1->appendChild($d->createElement('n'));
$p2->insertBefore($n, $anchor);

echo 'ib_xml=', $d->saveXML($r), "\n";
echo 'ib_p1_len=', $p1->childNodes->length, ' ib_p2_len=', $p2->childNodes->length, "\n";
echo 'ib_n_parent=', $n->parentNode->nodeName, "\n";
echo 'ib_item0=', $p2->childNodes->item(0)->nodeName, "\n";

$d2 = new DOMDocument();
$r2 = $d2->appendChild($d2->createElement('r'));
$a = $r2->appendChild($d2->createElement('a'));
$r2->appendChild($d2->createElement('b'));
$c = $a->appendChild($d2->createElement('c'));
$a->replaceChild($d2->createElement('x'), $c);
echo 'rc_same_xml=', $d2->saveXML($r2), "\n";

$d3 = new DOMDocument();
$r3 = $d3->appendChild($d3->createElement('r'));
$p1b = $r3->appendChild($d3->createElement('p1'));
$p2b = $r3->appendChild($d3->createElement('p2'));
$old = $p2b->appendChild($d3->createElement('old'));
$moved = $p1b->appendChild($d3->createElement('moved'));
$p2b->replaceChild($moved, $old);
echo 'rc_cross_xml=', $d3->saveXML($r3), "\n";
echo 'rc_p1_len=', $p1b->childNodes->length, ' rc_p2_len=', $p2b->childNodes->length, "\n";
echo 'rc_moved_parent=', $moved->parentNode->nodeName, "\n";
