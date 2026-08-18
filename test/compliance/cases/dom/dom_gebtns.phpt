--TEST--
stdlib DOMDocument::getElementsByTagNameNS live NodeList (#32415, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<r xmlns:n="http://example.com/ns"><n:a/><b/><n:c/></r>');
$list = $doc->getElementsByTagNameNS('http://example.com/ns', '*');
echo 'len=', $list->length, "\n";
echo 'i0=', $list->item(0)->localName, "\n";
echo 'i1=', $list->item(1)->localName, "\n";
--EXPECT--
len=2
i0=a
i1=c
