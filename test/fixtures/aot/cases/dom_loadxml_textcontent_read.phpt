--TEST--
AOT: loadXML user-script documentElement textContent/nodeValue read (#25475)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r>hi</r>');
echo 'text=', $d->documentElement->textContent, "\n";
echo 'node=', $d->documentElement->nodeValue, "\n";
echo 'xml=', trim($d->saveXML($d->documentElement)), "\n";
$d2 = new DOMDocument();
$d2->loadXML('<r><a>x</a><b>y</b></r>');
echo 'deep=', $d2->documentElement->textContent, "\n";
?>
--EXPECT--
text=hi
node=hi
xml=<r>hi</r>
deep=xy
