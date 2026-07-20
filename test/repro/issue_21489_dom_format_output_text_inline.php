<?php
declare(strict_types=1);

$d = new DOMDocument('1.0', 'UTF-8');
$d->formatOutput = true;
$r = $d->appendChild($d->createElement('root'));
$r->appendChild($d->createElement('child', 'x'));
$xml = $d->saveXML();
if (!str_contains($xml, '<child>x</child>')) {
    fwrite(STDERR, "FAIL: expected inline <child>x</child>\nGot:\n{$xml}\n");
    exit(1);
}
if (str_contains($xml, "<child>\n")) {
    fwrite(STDERR, "FAIL: text child must not be indented on its own line\nGot:\n{$xml}\n");
    exit(1);
}
echo "OK\n";
