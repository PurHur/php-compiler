--TEST--
stdlib DOMCharacterData::deleteData AOT xmlUTF8Strsub (#32389, ext/dom/characterdata.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$text = $doc->createTextNode('abcd');
$text->deleteData(1, 2);
echo $text->data, "\n";
--EXPECT--
ad
