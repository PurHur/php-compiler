--TEST--
AOT: empty documentElement is null; importNode+appendChild then documentElement (#32736)
--FILE--
<?php
$empty = new DOMDocument();
echo null === $empty->documentElement ? 'null' : 'set', "\n";
$src = new DOMDocument();
$src->loadXML('<a/>');
$dst = new DOMDocument();
$n = $dst->importNode($src->documentElement, true);
$dst->appendChild($n);
echo $dst->saveXML($dst->documentElement), "\n";
echo $dst->documentElement->nodeName, "\n";
--EXPECT--
null
<a/>
a
