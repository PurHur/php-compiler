--TEST--
DOM DOMDocument::$formatOutput indents saveXML() output (ext/dom/php_dom.c; #15335)
--FILE--
<?php
$d = new DOMDocument();
$d->formatOutput = true;
$d->preserveWhiteSpace = false;
$d->loadXML('<a><b><c/></b></a>');
$xml = $d->saveXML();
echo (int) (false !== strpos($xml, "\n  ")), "\n";
echo (int) str_contains($xml, '<c/>'), "\n";
--EXPECT--
1
1
