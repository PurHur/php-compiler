--TEST--
AOT: DOMText::wholeText after createTextNode must not be empty (#32395, ext/dom/text.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$text = $doc->createTextNode('ab');
echo $text->wholeText, "\n";
--EXPECT--
ab
