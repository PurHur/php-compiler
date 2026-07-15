--TEST--
SimpleXML: SimpleXMLElement::addAttribute live attrs (#19307, ext/simplexml/sxe.c)
--FILE--
<?php
$x = new SimpleXMLElement('<r/>');
$x->addAttribute('k', 'v');
echo trim($x->asXML()), "\n";
$x->addAttribute('p:a', '1', 'urn:x');
echo (string) $x['k'], "\n";
echo (string) $x['p:a'], "\n";
try {
    $x->addAttribute('', 'x');
} catch (ValueError $e) {
    echo 'empty:', $e->getMessage(), "\n";
}
--EXPECT--
<?xml version="1.0"?>
<r k="v"/>
v
1
empty:SimpleXMLElement::addAttribute(): Argument #1 ($qualifiedName) cannot be empty
