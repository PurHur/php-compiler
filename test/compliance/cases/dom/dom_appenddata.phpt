--TEST--
stdlib DOMCharacterData::appendData matches xmlTextConcat (#32376, ext/dom/characterdata.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$text = $doc->createTextNode('ab');
$text->appendData('cd');
echo $text->data, "\n";
--EXPECT--
abcd
