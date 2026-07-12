--TEST--
json json_encode(DOMDocument) — empty object not false (#18292, ext/json/php_json.c, ext/dom)
--FILE--
<?php
$dom = new DOMDocument();
$dom->loadHTML('<p>hi</p>');
$encoded = json_encode($dom);
echo $encoded, "\n";
var_export($encoded);
echo "\n";
echo json_last_error() === 0 ? '0' : (string) json_last_error(), "\n";
--EXPECT--
{}
'{}'
0
