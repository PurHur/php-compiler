--TEST--
SimpleXML: SimpleXMLElement::saveXML alias of asXML (#19413, ext/simplexml/sxe.c)
--FILE--
<?php
$s = simplexml_load_string('<r><c>t</c></r>');
echo 'has=', method_exists($s, 'saveXML') ? '1' : '0', "\n";
echo 'eq=', ($s->saveXML() === $s->asXML()) ? '1' : '0', "\n";
echo trim($s->saveXML()), "\n";
--EXPECT--
has=1
eq=1
<?xml version="1.0"?>
<r><c>t</c></r>
