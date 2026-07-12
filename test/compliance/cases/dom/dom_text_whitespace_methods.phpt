--TEST--
dom DOMText whitespace predicates (#17543, ext/dom/text.c)
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$ws = $doc->createTextNode('  ');
$root->appendChild($ws);
echo (int) method_exists($ws, 'isElementContentWhitespace'), "\n";
echo (int) method_exists($ws, 'isWhitespaceInElementContent'), "\n";
echo (int) $ws->isWhitespaceInElementContent(), "\n";
echo (int) $ws->isElementContentWhitespace(), "\n";
$nonWs = $doc->createTextNode('x');
$root->appendChild($nonWs);
echo (int) $nonWs->isWhitespaceInElementContent(), "\n";
$detached = $doc->createTextNode("\t");
echo (int) $detached->isWhitespaceInElementContent(), "\n";
?>
--EXPECT--
1
1
1
1
0
1
