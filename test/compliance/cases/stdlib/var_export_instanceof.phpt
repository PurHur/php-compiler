--TEST--
stdlib: var_export($expr instanceof Class) and property prelude (#17540, ext/standard/var.c)
--FILE--
<?php
var_export('hi' instanceof stdClass);
echo "\n";
var_export((new stdClass()) instanceof stdClass);
echo "\n";

$doc = new DOMDocument();
$text = $doc->createTextNode('hello');
var_export($text instanceof DOMText);
echo "\n";
var_export($text->data);
echo "\n";

$is = $text instanceof DOMText;
var_export($is);
echo "\n";
var_export($text instanceof DOMText, true);
echo "\n";
--EXPECT--
false
true
true
'hello'
true
