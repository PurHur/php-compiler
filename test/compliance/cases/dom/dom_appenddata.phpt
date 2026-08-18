--TEST--
stdlib DOMText::appendData matches xmlTextConcat (#32376, ext/dom/characterdata.c)
--FILE--
<?php
$doc = new DOMDocument();
$text = $doc->createTextNode('ab');
$text->appendData('cd');
echo $text->data, "\n";
--EXPECT--
abcd
