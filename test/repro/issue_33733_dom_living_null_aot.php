<?php
// #33733 — isEqualNode / compareDocumentPosition variable-null (ext/dom/php_dom.c)
// Requires PROFILE=8.4 living DOM APIs.
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
