<?php
// AOT: DOMElement::replaceChild must not collapse saveXML to the replacement only
// (dual-path #33379 document fold rewrite poison).
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$r = $d->documentElement;
$held = $r->childNodes;
$r->replaceChild($d->createElement('x'), $held->item(1));
echo 'held_len=' . $held->length . "\n";
echo 'de=' . $d->documentElement->tagName . "\n";
echo 'xml=' . str_replace("\n", '\\n', trim($d->saveXML())) . "\n";
