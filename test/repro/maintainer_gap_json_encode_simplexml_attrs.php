<?php
declare(strict_types=1);

// json_encode(SimpleXMLElement) @attributes wire (#18291).
$xml = simplexml_load_string('<root id="1"><item>a</item></root>');
$encoded = json_encode($xml);
if ('{"@attributes":{"id":"1"},"item":"a"}' !== $encoded) {
    fwrite(STDERR, "expected @attributes wire, got ".var_export($encoded, true)."\n");
    exit(1);
}
echo "ok\n";
