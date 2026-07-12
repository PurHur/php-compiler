--TEST--
stdlib DOMCharacterData::length (#17618, ext/dom/characterdata.c)
--FILE--
<?php
$doc = new DOMDocument();
$text = $doc->createTextNode('hi');
var_export(property_exists($text, 'length'));
echo "\n";
var_export($text->length);
echo "\n";
$text->appendData(' there');
var_export($text->length);
echo "\n";
$comment = $doc->createComment('note');
var_export($comment->length);
echo "\n";
?>
--EXPECT--
true
2
8
4
