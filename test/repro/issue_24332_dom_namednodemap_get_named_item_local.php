<?php
/** Repro #24332 — DOMNamedNodeMap::getNamedItem local-name vs QName (ext/dom/namednodemap.c). */
$doc = new DOMDocument();
$doc->loadXML('<root xmlns:x="urn:x" x:a="1" b="2"/>');
$m = $doc->documentElement->attributes;
echo 'local=', var_export($m->getNamedItem('a')?->nodeValue, true), "\n";
echo 'qname=', var_export($m->getNamedItem('x:a')?->nodeValue, true), "\n";
echo 'plain=', var_export($m->getNamedItem('b')?->nodeValue, true), "\n";
echo 'ns=', var_export($m->getNamedItemNS('urn:x', 'a')?->nodeValue, true), "\n";
