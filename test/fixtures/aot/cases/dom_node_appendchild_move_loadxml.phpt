--TEST--
AOT: DOMNode::appendChild move after loadXML — live childNodes (#28672)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<r><a>1</a><b>2</b></r>');
$r = $doc->documentElement;
$a = $r->firstChild;
$r->appendChild($a);
echo $r->childNodes->length, "\n";
echo $r->childNodes->item(0)->nodeName, "\n";
echo $r->childNodes->item(1)->nodeName, "\n";
--EXPECT--
2
b
a
