--TEST--
stdlib DOMCharacterData::replaceData matches php-src replace_data (ext/dom/characterdata.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$text = $doc->createTextNode('abcd');
$text->replaceData(1, 2, 'XY');
echo $text->data, "\n";
--EXPECT--
aXYd
