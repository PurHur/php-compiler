--TEST--
AOT DOMCharacterData::substringData createTextNode (#32372, ext/dom/characterdata.c)
--FILE--
<?php
$doc = new DOMDocument();
$t = $doc->createTextNode('abcd');
echo $t->substringData(1, 2), '|', $t->substringData(0, 4), "\n";
--EXPECT--
bc|abcd
