<?php
declare(strict_types=1);

$d = new DOMDocument();
$d->formatOutput = true;
$d->preserveWhiteSpace = false;
$d->loadXML('<a><b><c/></b></a>');
$xml = $d->saveXML();
if (false === strpos($xml, "\n  ")) {
    fwrite(STDERR, "FAIL: saveXML() with formatOutput=true should indent child elements\n");
    exit(1);
}
echo "OK\n";
