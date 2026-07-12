<?php
declare(strict_types=1);

// json_encode(SimpleXMLElement) tree wire (#18291).
$xml = simplexml_load_string('<root><item>a</item><item>b</item></root>');
$encoded = json_encode($xml);
if ('{"item":["a","b"]}' !== $encoded) {
    fwrite(STDERR, "expected {\"item\":[\"a\",\"b\"]}, got ".var_export($encoded, true)."\n");
    exit(1);
}
echo "ok\n";
