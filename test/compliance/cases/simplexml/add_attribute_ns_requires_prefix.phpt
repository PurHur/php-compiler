--TEST--
SimpleXML: addAttribute namespace without prefix warns + unchanged (#19708, ext/simplexml/sxe.c)
--FILE--
<?php
$s = new SimpleXMLElement('<r/>');
$s->addAttribute('x', '1', 'urn:n');
echo trim($s->asXML()), "\n";

$t = new SimpleXMLElement('<r/>');
$t->addAttribute('n:x', '1', 'urn:n');
echo trim($t->asXML()), "\n";
echo (string) $t['n:x'], "\n";
--EXPECTF--
PHP Warning:  SimpleXMLElement::addAttribute(): Attribute requires prefix for namespace in %s on line %d
<?xml version="1.0"?>
<r/>
<?xml version="1.0"?>
<r xmlns:n="urn:n" n:x="1"/>
1
