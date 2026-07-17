--TEST--
SimpleXMLElement children() filters by namespace like Zend (#19342, ext/simplexml/sxe.c)
--FILE--
<?php
$d = new SimpleXMLElement('<?xml version="1.0"?><r xmlns="urn:default"><x>1</x></r>');
echo 'default_ok=', (string) $d->x, "\n";
$sx = new SimpleXMLElement('<r xmlns:a="urn:a"><a:x>1</a:x><y>2</y></r>');
echo 'default_children=';
foreach ($sx->children() as $c) {
    echo $c->getName(), '=', (string) $c, ';';
}
echo "\nns_children=";
foreach ($sx->children('urn:a') as $c) {
    echo $c->getName(), '=', (string) $c, ';';
}
echo "\n";
--EXPECT--
default_ok=1
default_children=y=2;
ns_children=x=1;
