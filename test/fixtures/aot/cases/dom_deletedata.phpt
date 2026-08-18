--TEST--
AOT DOMCharacterData::deleteData createTextNode (ext/dom/characterdata.c xmlTextDelete)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$text = $doc->createTextNode('abcd');
$text->deleteData(1, 2);
echo $text->data, "\n";
--EXPECT--
ad
