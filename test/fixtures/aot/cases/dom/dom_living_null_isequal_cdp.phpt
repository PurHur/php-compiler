--TEST--
AOT: isEqualNode / compareDocumentPosition variable-null (#33733, ext/dom/php_dom.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$a = $doc->createElement('a');
$doc->appendChild($a);

$n = null;
echo (int) $a->isEqualNode($n), "\n";
$miss = $doc->getElementById('nope');
echo (int) $a->isEqualNode($miss), "\n";

try {
    $a->compareDocumentPosition($n);
    echo "no-throw\n";
} catch (TypeError $e) {
    echo "TE\n";
}
--EXPECT--
0
0
TE
