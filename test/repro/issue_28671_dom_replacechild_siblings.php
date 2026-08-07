<?php
declare(strict_types=1);

/**
 * #28671 — AOT replaceChild must keep remaining siblings in saveXML.
 * Zend/VM/JIT: <r><c/><b/></r> len=2. Was AOT <r><c/></r> with len=2.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/></r>');
$r = $doc->documentElement;
$a = $r->firstChild;
$n = $doc->createElement('c');
$r->replaceChild($n, $a);
echo $doc->saveXML($r), "\n";
echo 'len=', $r->childNodes->length, "\n";
echo 'first=', $r->firstChild->nodeName, ' last=', $r->lastChild->nodeName, "\n";
