--TEST--
SimpleXMLElement::children() live view — addChild visible (#20331, ext/simplexml/sxe.c)
--FILE--
<?php
$x = simplexml_load_string('<r><a/></r>');
$ch = $x->children();
echo 'before=', count($ch), "\n";
$x->addChild('b');
$names = [];
foreach ($ch as $c) {
    $names[] = $c->getName();
}
echo 'after=', count($ch), ' names=', json_encode($names), "\n";

$x2 = simplexml_load_string('<r xmlns:p="urn:p"><p:a/></r>');
$ns = $x2->children('urn:p', false);
echo 'ns_before=', count($ns), "\n";
$x2->addChild('p:b', null, 'urn:p');
echo 'ns_after=', count($ns), "\n";
--EXPECT--
before=1
after=2 names=["a","b"]
ns_before=1
ns_after=2
