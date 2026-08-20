<?php
/** AOT: insertBefore($node, null) / omitted / variable null ≡ append (#33031 / re-#26458). */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$a = $d->createElement('a');
$b = $d->createElement('b');
$r->appendChild($a);
$r->insertBefore($b, null);
echo $r->childNodes->length, ' ', $d->saveXML($r), "\n";

$c = $d->createElement('c');
$r->insertBefore($c);
echo $r->childNodes->length, ' ', $d->saveXML($r), "\n";

$doc = new DOMDocument();
$doc->loadXML('<r><a/></r>');
$root = $doc->documentElement;
$x = $doc->createElement('x');
$ref = null;
$root->insertBefore($x, $ref);
echo $doc->saveXML($root), "\n";
