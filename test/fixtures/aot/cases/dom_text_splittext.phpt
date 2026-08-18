--TEST--
AOT: DOMText::splitText must not abort as DOMText::splittext (#32362, ext/dom/text.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$text = $doc->createTextNode('abcd');
$tail = $text->splitText(2);
echo $text->data, '|', $tail->data, "\n";
--EXPECT--
ab|cd
