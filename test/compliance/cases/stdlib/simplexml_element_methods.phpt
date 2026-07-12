--TEST--
SimpleXML: instance methods getName/children/asXML/xpath (#18038, ext/simplexml/sxe.c)
--FILE--
<?php
$x = simplexml_load_string('<r id="1"><a/><b/></r>');
echo $x->getName(), "\n";
$kids = $x->children();
echo count($kids), "\n";
echo $kids[0]->getName(), "\n";
echo trim($x->asXML()), "\n";
$found = $x->xpath('//a');
echo count($found), "\n";
echo $found[0]->getName(), "\n";
$attrs = $x->attributes();
echo (string) $attrs['id'], "\n";
$added = $x->addChild('c', 'text');
echo $added->getName(), "\n";
echo trim($x->asXML()), "\n";
--EXPECT--
r
2
a
<?xml version="1.0"?>
<r id="1"><a/><b/></r>
1
a
1
c
<?xml version="1.0"?>
<r id="1"><a/><b/><c>text</c></r>
