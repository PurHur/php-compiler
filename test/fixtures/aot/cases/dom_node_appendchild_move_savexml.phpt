--TEST--
AOT: appendChild move after loadXML — saveXML order (#31684)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<r><a>1</a><b>2</b></r>');
$r = $doc->documentElement;
$a = $r->firstChild;
$next = $a->nextSibling;
if (null === $next) {
    echo "null\n";
} else {
    echo $next->nodeName, "\n";
}
$r->appendChild($a);
echo $doc->saveXML($r), "\n";
echo $r->childNodes->length, "\n";
echo $r->childNodes->item(0)->nodeName, "\n";
echo $r->childNodes->item(1)->nodeName, "\n";
--EXPECT--
b
<r><b>2</b><a>1</a></r>
2
b
a
