--TEST--
AOT: importNode into empty DOMDocument then document-wide saveXML (#33697)
--FILE--
<?php
$src = new DOMDocument();
$src->loadXML('<r><a id="1"><b/></a></r>');
$dst = new DOMDocument();
$n = $dst->importNode($src->documentElement->firstChild, false);
$dst->appendChild($n);
echo 'name=', $n->nodeName, ' kids=', $n->childNodes->length, "\n";
echo 'de=', $dst->documentElement->nodeName, "\n";
echo 'full=', trim($dst->saveXML()), "\n";
echo 'node=', trim($dst->saveXML($n)), "\n";
echo 'src=', trim($src->saveXML()), "\n";
?>
--EXPECT--
name=a kids=0
de=a
full=<?xml version="1.0"?>
<a id="1"/>
node=<a id="1"/>
src=<?xml version="1.0"?>
<r><a id="1"><b/></a></r>
