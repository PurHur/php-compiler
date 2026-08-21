<?php

declare(strict_types=1);

/**
 * AOT: cross-parent appendChild must detach from the old parent (#33404).
 * php-src: ext/dom/node.c dom_node_append_child
 */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$p1 = $r->appendChild($d->createElement('p1'));
$p2 = $r->appendChild($d->createElement('p2'));
$n = $p1->appendChild($d->createElement('n'));
$p2->appendChild($n);

echo 'xml=', $d->saveXML($r), "\n";
echo 'p1_len=', $p1->childNodes->length, ' p2_len=', $p2->childNodes->length, "\n";
echo 'n_parent=', $n->parentNode->nodeName, "\n";
echo 'p1_xml=', $d->saveXML($p1), ' p2_xml=', $d->saveXML($p2), "\n";
echo 'item0_same=', ($p2->childNodes->item(0) === $n ? '1' : '0'), "\n";
