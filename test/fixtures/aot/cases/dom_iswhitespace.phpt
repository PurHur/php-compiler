--TEST--
AOT: DOMText::isWhitespaceInElementContent must not abort (#32396, ext/dom/text.c xmlIsBlankNode)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$t = $doc->createTextNode('ab');
echo (int) $t->isWhitespaceInElementContent(), "\n";
$w = $doc->createTextNode(" \t\n");
echo (int) $w->isWhitespaceInElementContent(), "\n";
--EXPECT--
0
1
