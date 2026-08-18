--TEST--
AOT: DOMText::appendData must not abort as DOMText::appenddata (#32376, ext/dom/characterdata.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$text = $doc->createTextNode('ab');
$text->appendData('cd');
echo $text->data, "\n";
--EXPECT--
abcd
