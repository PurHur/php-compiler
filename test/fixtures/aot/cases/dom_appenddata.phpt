--TEST--
AOT DOMCharacterData::appendData createTextNode (#32376, ext/dom/characterdata.c xmlTextConcat)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$text = $doc->createTextNode('ab');
$text->appendData('cd');
echo $text->data, "\n";
--EXPECT--
abcd
