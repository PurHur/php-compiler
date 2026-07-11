<?php

declare(strict_types=1);

$doc = new DOMDocument();
$root = $doc->createElement('r');
$doc->appendChild($root);
$child = $doc->createElement('c');
$root->appendChild($child);
$root->insertBefore($doc->createComment('note'), $child);
$xml = $doc->saveXML($root);
if (false === strpos($xml, '<!--note-->')) {
    fwrite(STDERR, "fail: missing comment in saveXML: $xml\n");
    exit(1);
}
if (false === strpos($xml, '<c/>') && false === strpos($xml, '<c></c>') && false === strpos($xml, '<c/>')) {
    fwrite(STDERR, "fail: missing child element in saveXML: $xml\n");
    exit(1);
}
echo trim($xml), "\n";
echo "ok\n";
