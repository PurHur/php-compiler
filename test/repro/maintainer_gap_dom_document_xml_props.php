<?php

declare(strict_types=1);

$doc = new DOMDocument();

if (null !== $doc->encoding) {
    fwrite(STDERR, "fail: fresh encoding should be null\n");
    exit(1);
}
if ('1.0' !== $doc->xmlVersion) {
    fwrite(STDERR, "fail: fresh xmlVersion expected 1.0, got {$doc->xmlVersion}\n");
    exit(1);
}
if (true === $doc->xmlStandalone) {
    fwrite(STDERR, "fail: fresh xmlStandalone should be false\n");
    exit(1);
}
if (null !== $doc->documentURI) {
    fwrite(STDERR, "fail: fresh documentURI should be null\n");
    exit(1);
}

$doc->encoding = 'UTF-8';
if ('UTF-8' !== $doc->encoding) {
    fwrite(STDERR, "fail: encoding round-trip\n");
    exit(1);
}

$doc->xmlStandalone = true;
if (!$doc->xmlStandalone) {
    fwrite(STDERR, "fail: xmlStandalone assign\n");
    exit(1);
}

$doc->documentURI = 'file:///tmp/test.xml';
if ('file:///tmp/test.xml' !== $doc->documentURI) {
    fwrite(STDERR, "fail: documentURI round-trip\n");
    exit(1);
}

$doc->loadXML('<?xml version="1.0" encoding="ISO-8859-1" standalone="yes"?><root/>');
if ('ISO-8859-1' !== $doc->encoding) {
    fwrite(STDERR, "fail: loadXML encoding got {$doc->encoding}\n");
    exit(1);
}
if (!$doc->xmlStandalone) {
    fwrite(STDERR, "fail: loadXML standalone\n");
    exit(1);
}

$xml = $doc->saveXML();
if (!str_contains($xml, 'encoding="ISO-8859-1"')) {
    fwrite(STDERR, "fail: saveXML missing encoding\n");
    exit(1);
}
if (!str_contains($xml, 'standalone="yes"')) {
    fwrite(STDERR, "fail: saveXML missing standalone\n");
    exit(1);
}

echo "ok\n";
