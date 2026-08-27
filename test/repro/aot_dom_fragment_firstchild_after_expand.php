<?php
// AOT: DocumentFragment firstChild/lastChild after parent appendChild empties fragment (#35518).
// Requires loadXML so seedChildOwnerFromParent runs (pure user-script path).
$d = new DOMDocument();
$d->loadXML('<root/>');
$parent = $d->documentElement;
$frag = $d->createDocumentFragment();
$frag->appendChild($d->createElement('a'));
$parent->appendChild($frag);
var_export($frag->firstChild);
echo "\n";
var_export($frag->lastChild);
echo "\n";
echo 'len=', $frag->childNodes->length, "\n";
