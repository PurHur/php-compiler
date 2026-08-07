<?php
declare(strict_types=1);

/**
 * #28672 — AOT loadXML tree: appendChild move must keep live childNodes.
 * Zend/VM/JIT: len=2 c0=b c1=a. Was AOT segfault after c:main_before_php.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a>1</a><b>2</b></r>');
$r = $doc->documentElement;
$a = $r->firstChild;
$r->appendChild($a);
echo 'len=', $r->childNodes->length, "\n";
echo 'c0=', $r->childNodes->item(0)->nodeName, "\n";
echo 'c1=', $r->childNodes->item(1)->nodeName, "\n";
