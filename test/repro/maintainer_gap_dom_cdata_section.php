<?php

declare(strict_types=1);

if (!class_exists('DOMCDATASection', false)) {
    fwrite(STDERR, "fail: DOMCDATASection class missing\n");
    exit(1);
}

$doc = new DOMDocument();
$section = $doc->createCDATASection('hi');
if (!$section instanceof DOMCDATASection) {
    fwrite(STDERR, "fail: createCDATASection() did not return DOMCDATASection\n");
    exit(1);
}
if ('hi' !== $section->data) {
    fwrite(STDERR, "fail: createCDATASection data mismatch\n");
    exit(1);
}

if (!$doc->loadXML('<a><![CDATA[x]]></a>')) {
    fwrite(STDERR, "fail: loadXML with CDATA returned false\n");
    exit(1);
}

$child = $doc->documentElement->firstChild;
if (!$child instanceof DOMCDATASection) {
    fwrite(STDERR, 'fail: loadXML child class '.($child ? $child::class : 'null')."\n");
    exit(1);
}
if ('x' !== $child->data) {
    fwrite(STDERR, "fail: loadXML CDATA data mismatch\n");
    exit(1);
}

$roundTrip = $doc->saveXML($child);
if ('<![CDATA[x]]>' !== $roundTrip) {
    fwrite(STDERR, "fail: saveXML round-trip got {$roundTrip}\n");
    exit(1);
}

echo "ok\n";
