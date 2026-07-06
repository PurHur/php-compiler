--TEST--
stdlib DOMText / DOMComment / DOMCharacterData (#6250, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$text = $doc->createTextNode('hello');
var_export($text instanceof DOMText);
echo "\n";
var_export($text->data);
echo "\n";
$comment = $doc->createComment('note');
var_export($comment instanceof DOMComment);
echo "\n";
var_export($comment->data);
echo "\n";
var_export($text->substringData(0, 2));
echo "\n";
?>
--EXPECT--
true
'hello'
true
'note'
'he'
