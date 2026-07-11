<?php

declare(strict_types=1);

if (!function_exists('dom_import_simplexml') || !function_exists('simplexml_import_dom')) {
    fwrite(STDERR, "fail: bridge functions missing\n");
    exit(1);
}

$sxe = simplexml_load_string('<root><item id="1">a</item></root>');
if (false === $sxe) {
    fwrite(STDERR, "fail: simplexml_load_string\n");
    exit(1);
}

$dom = dom_import_simplexml($sxe);
if (!($dom instanceof DOMElement)) {
    fwrite(STDERR, 'fail: dom_import_simplexml must return DOMElement, got '.get_debug_type($dom)."\n");
    exit(1);
}
if ('root' !== $dom->nodeName) {
    fwrite(STDERR, 'fail: expected root element, got '.$dom->nodeName."\n");
    exit(1);
}

$items = $dom->getElementsByTagName('item');
if (1 !== $items->length || 'a' !== $items->item(0)->textContent || '1' !== $items->item(0)->getAttribute('id')) {
    fwrite(STDERR, "fail: nested item mismatch\n");
    exit(1);
}

$back = simplexml_import_dom($dom);
if (!($back instanceof SimpleXMLElement)) {
    fwrite(STDERR, 'fail: simplexml_import_dom must return SimpleXMLElement, got '.get_debug_type($back)."\n");
    exit(1);
}
if ('a' !== (string) $back->item[0] || '1' !== (string) $back->item[0]['id']) {
    fwrite(STDERR, "fail: round-trip item mismatch\n");
    exit(1);
}

try {
    dom_import_simplexml(new stdClass());
    fwrite(STDERR, "fail: dom_import_simplexml(stdClass) should throw\n");
    exit(1);
} catch (ValueError) {
    // expected
}

try {
    simplexml_import_dom(new stdClass());
    fwrite(STDERR, "fail: simplexml_import_dom(stdClass) should throw\n");
    exit(1);
} catch (TypeError) {
    // expected
}

echo "ok\n";
