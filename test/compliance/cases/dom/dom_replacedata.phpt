--TEST--
stdlib DOMCharacterData::replaceData matches xmlTextReplace (#32391, ext/dom/characterdata.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$text = $doc->createTextNode('axc');
$text->replaceData(1, 1, 'b');
echo $text->data, "\n";
--EXPECT--
abc
