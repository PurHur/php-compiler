--TEST--
ext/dom DOMDocument::saveXML() inline PropertyFetch + LIBXML_NOEMPTYTAG (#25292, ext/dom/document.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
echo $d->saveXML($d->documentElement, LIBXML_NOEMPTYTAG);
echo "\n";
--EXPECT--
<r><a></a></r>
