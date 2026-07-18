--TEST--
DOMElement getElementsByTagName("*") / NS excludes context (#20377)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$r = $d->documentElement;
$list = $r->getElementsByTagName('*');
echo 'star0=', $list->length, "\n";
for ($i = 0; $i < $list->length; $i++) {
    echo 'i', $i, '=', $list->item($i)->nodeName, "\n";
}
$r->appendChild($d->createElement('d'));
echo 'star1=', $list->length, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r xmlns:h="http://ex.com"><h:a/><b/></r>');
$r2 = $d2->documentElement;
echo 'ns=', $r2->getElementsByTagNameNS('*', '*')->length, "\n";

$d3 = new DOMDocument();
$d3->loadXML('<a><a/><b/></a>');
$a = $d3->documentElement;
echo 'named_self=', $a->getElementsByTagName('a')->length, "\n";
echo 'doc_named=', $d3->getElementsByTagName('a')->length, "\n";
echo 'doc_star=', $d3->getElementsByTagName('*')->length, "\n";
--EXPECT--
star0=3
i0=a
i1=b
i2=c
star1=4
ns=2
named_self=1
doc_named=2
doc_star=3
