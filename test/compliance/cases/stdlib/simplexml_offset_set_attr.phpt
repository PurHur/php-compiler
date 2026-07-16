--TEST--
SimpleXML: ArrayAccess offsetSet attribute write (#19536, ext/simplexml/sxe.c)
--FILE--
<?php
$x = new SimpleXMLElement('<root/>');
$c = $x->addChild('item', 'hi');
$c['id'] = '1';
echo trim($x->asXML()), "\n";
$c['id'] = '2';
echo trim($x->asXML()), "\n";
$c['empty'] = '';
echo trim($x->asXML()), "\n";
unset($c['id']);
echo trim($x->asXML()), "\n";
try {
    $c[''] = 'x';
    echo "empty-name-ok\n";
} catch (ValueError $e) {
    echo "empty-name:", $e->getMessage(), "\n";
}
--EXPECT--
<?xml version="1.0"?>
<root><item id="1">hi</item></root>
<?xml version="1.0"?>
<root><item id="2">hi</item></root>
<?xml version="1.0"?>
<root><item id="2" empty="">hi</item></root>
<?xml version="1.0"?>
<root><item empty="">hi</item></root>
empty-name:Cannot create attribute with an empty name
