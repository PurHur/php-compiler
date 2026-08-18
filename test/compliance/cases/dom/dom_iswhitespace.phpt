--TEST--
stdlib DOMText::isWhitespaceInElementContent AOT xmlIsBlankNode (#32396, ext/dom/text.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$t = $doc->createTextNode('ab');
echo (int) $t->isWhitespaceInElementContent(), "\n";
$w = $doc->createTextNode(" \t\n");
echo (int) $w->isWhitespaceInElementContent(), "\n";
$e = $doc->createTextNode('');
echo (int) $e->isWhitespaceInElementContent(), "\n";
echo (int) $w->isElementContentWhitespace(), "\n";
--EXPECT--
0
1
1
1
