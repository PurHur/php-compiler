<?php
// #33775 — isSameNode(null) TypeError (ext/dom/php_dom.c / php_dom.stub.php)
$doc = new DOMDocument();
$a = $doc->createElement('a');
$b = $doc->createElement('b');
$doc->appendChild($a);
$doc->documentElement->appendChild($b);

echo (int) $a->isSameNode($a), "\n";
echo (int) $a->isSameNode($b), "\n";

$n = null;
try {
    $a->isSameNode($n);
    echo "no-throw\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), '$otherNode') ? 'TE' : 'TE-badmsg'), "\n";
}

try {
    $a->isSameNode(null);
    echo "no-throw-lit\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), '$otherNode') ? 'TE-lit' : 'TE-lit-badmsg'), "\n";
}
