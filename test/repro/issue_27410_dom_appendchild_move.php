<?php

/**
 * #27410 — AOT DOMDocument::appendChild() move: live childNodes length + item(1).
 * Expect: 1|0|b
 */
$doc = new DOMDocument();
$a = $doc->createElement('a');
$b = $doc->createElement('b');
$doc->appendChild($a);
$a->appendChild($b);
echo $a->childNodes->length, '|';
$doc->appendChild($b);
echo $a->childNodes->length, '|';
$n = $doc->childNodes->item(1);
echo $n ? $n->nodeName : 'null', "\n";
