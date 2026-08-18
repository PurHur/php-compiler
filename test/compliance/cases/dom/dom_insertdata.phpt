--TEST--
stdlib DOMCharacterData::insertData AOT xmlTextInsert (#32380, ext/dom/characterdata.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$text = $doc->createTextNode('ac');
$text->insertData(1, 'b');
echo $text->data, "\n";
--EXPECT--
abc
