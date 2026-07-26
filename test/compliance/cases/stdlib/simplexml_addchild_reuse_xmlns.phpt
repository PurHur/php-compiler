--TEST--
SimpleXML: addChild reuses in-scope xmlns (no redundant decl) (#22734, ext/simplexml/simplexml.c)
--FILE--
<?php
$x = new SimpleXMLElement('<r xmlns:ns="urn:x"><ns:keep/></r>');
$x->addChild('ns:item', 'v', 'urn:x');
echo str_replace("\n", '', $x->asXML()), "\n";

$y = new SimpleXMLElement('<r xmlns="urn:default"><keep/></r>');
$y->addChild('item', 'v', 'urn:default');
echo str_replace("\n", '', $y->asXML()), "\n";

$z = new SimpleXMLElement('<r xmlns:other="urn:x"/>');
$z->addChild('ns:item', 'v', 'urn:x');
echo str_replace("\n", '', $z->asXML()), "\n";

$r = new SimpleXMLElement('<r xmlns:ns="urn:old"/>');
$r->addChild('ns:item', 'v', 'urn:new');
echo str_replace("\n", '', $r->asXML()), "\n";

$g = new SimpleXMLElement('<r xmlns:ns="urn:x"/>');
$mid = $g->addChild('mid');
$mid->addChild('foo:item', 'v', 'urn:x');
echo str_replace("\n", '', $g->asXML()), "\n";
--EXPECT--
<?xml version="1.0"?><r xmlns:ns="urn:x"><ns:keep/><ns:item>v</ns:item></r>
<?xml version="1.0"?><r xmlns="urn:default"><keep/><item>v</item></r>
<?xml version="1.0"?><r xmlns:other="urn:x"><other:item>v</other:item></r>
<?xml version="1.0"?><r xmlns:ns="urn:old"><ns:item xmlns:ns="urn:new">v</ns:item></r>
<?xml version="1.0"?><r xmlns:ns="urn:x"><mid><ns:item>v</ns:item></mid></r>
