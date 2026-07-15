<?php

declare(strict_types=1);

$doc = new DOMDocument();
$attr = $doc->createAttributeNS('http://example.com', 'ex:foo');
if (false !== $attr) {
    fwrite(STDERR, 'fail: expected false, got '.get_debug_type($attr)."\n");
    exit(1);
}

$doc->loadXML('<root/>');
$attr = $doc->createAttributeNS('http://example.com', 'ex:foo');
if (!$attr instanceof DOMAttr) {
    fwrite(STDERR, 'fail: expected DOMAttr after root, got '.get_debug_type($attr)."\n");
    exit(1);
}

echo "ok\n";
