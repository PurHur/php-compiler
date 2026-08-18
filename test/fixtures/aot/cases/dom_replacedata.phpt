--TEST--
AOT DOMCharacterData::replaceData createTextNode (ext/dom/characterdata.c replace_data)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$text = $doc->createTextNode('abcd');
$text->replaceData(1, 2, 'XY');
echo $text->data, "\n";
--EXPECT--
aXYd
