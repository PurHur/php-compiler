--TEST--
AOT: loadXML children survive saveXML + appendChild (#26757, re-#23251)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<root><x/></root>');
echo 'len=', $d->documentElement->childNodes->length, "\n";
echo 'xml=', trim($d->saveXML($d->documentElement)), "\n";
$d->documentElement->appendChild($d->createElement('a'));
echo 'after=', trim($d->saveXML($d->documentElement)), "\n";
--EXPECT--
len=1
xml=<root><x/></root>
after=<root><x/><a/></root>
