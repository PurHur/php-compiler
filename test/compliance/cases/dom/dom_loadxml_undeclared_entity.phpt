--TEST--
dom DOMDocument::loadXML() undeclared entity fails + libxml error 26 (#22774, ext/dom/document.c)
--FILE--
<?php
libxml_use_internal_errors(true);
libxml_clear_errors();

$d = new DOMDocument();
$ok = $d->loadXML('<r>&foo;</r>');
$errors = libxml_get_errors();
echo ($ok ? 'true' : 'false'), "\n";
echo count($errors), "\n";
echo $errors[0]->code, "\n";
echo $errors[0]->level, "\n";
echo trim($errors[0]->message), "\n";
echo $errors[0]->column, "\n";
echo ('<?xml version="1.0"?>' . "\n" === $d->saveXML()) ? "empty\n" : "not-empty\n";

libxml_clear_errors();
$d2 = new DOMDocument();
$d2->loadXML('<ok>1</ok>');
$ok2 = $d2->loadXML('<r>&foo;</r>');
echo ($ok2 ? 'true' : 'false'), "\n";
echo str_contains($d2->saveXML(), '<ok>1</ok>') ? "kept\n" : "cleared\n";

libxml_clear_errors();
$d3 = new DOMDocument();
$ok3 = $d3->loadXML('<!DOCTYPE r [<!ENTITY e "hi">]><r>&e;</r>');
echo ($ok3 ? 'true' : 'false'), "\n";
echo get_class($d3->documentElement->firstChild), "\n";

libxml_clear_errors();
$d4 = new DOMDocument();
$ok4 = $d4->loadXML('<r a="&bar;">x</r>');
$e4 = libxml_get_errors();
echo ($ok4 ? 'true' : 'false'), "\n";
echo $e4[0]->code, "\n";
echo trim($e4[0]->message), "\n";
--EXPECT--
false
1
26
3
Entity 'foo' not defined
9
empty
false
kept
true
DOMEntityReference
false
26
Entity 'bar' not defined
