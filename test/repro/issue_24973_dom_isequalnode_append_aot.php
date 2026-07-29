<?php
// #24973 — AOT DOMNode::isEqualNode after appendChild (PROFILE=8.4)
$doc = new DOMDocument();
$a = $doc->createElement('a');
$doc->appendChild($a);
echo (int) $a->isEqualNode($a), "\n";
$doc2 = new DOMDocument();
$b = $doc2->createElement('a');
$doc2->appendChild($b);
echo (int) $a->isEqualNode($b), "\n";
echo (int) $a->isSameNode($b), "\n";
$doc3 = new DOMDocument();
$d = $doc3->createElement('z');
$doc3->appendChild($d);
echo (int) $a->isEqualNode($d), "\n";
