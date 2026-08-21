<?php
/**
 * #33327 — AOT insertBefore(DocumentFragment) before a middle child must splice
 * INNER_XML (saveXML), not append fragment markup. Live childNodes already OK.
 * php-src: ext/dom/node.c dom_node_insert_before
 */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $r->appendChild($d->createElement('a'));
$b = $r->appendChild($d->createElement('b'));
$f = $d->createDocumentFragment();
$f->appendChild($d->createElement('x'));
$f->appendChild($d->createElement('y'));
$r->insertBefore($f, $b);
echo 'len=', $r->childNodes->length, "\n";
for ($i = 0; $i < $r->childNodes->length; $i++) {
    $n = $r->childNodes->item($i);
    echo 'i', $i, '=', $n ? $n->nodeName : 'null', "\n";
}
echo 'xml=', $d->saveXML($r), "\n";
