--TEST--
json JSON_THROW_ON_ERROR — json_decode/json_encode throw JsonException (ext/json/php_json.c)
--FILE--
<?php

try {
    json_decode('{', null, 512, JSON_THROW_ON_ERROR);
    echo "decode: no throw\n";
} catch (JsonException $e) {
    echo "decode: ", get_class($e), " code=", $e->getCode(), "\n";
}

var_export(json_decode('{'));
echo "\n";
echo "decode: last_error=", json_last_error(), "\n";

try {
    json_encode("\xB1\x31", JSON_THROW_ON_ERROR);
    echo "encode: no throw\n";
} catch (JsonException $e) {
    echo "encode: ", get_class($e), " code=", $e->getCode(), "\n";
}

var_export(json_encode("\xB1\x31"));
echo "\n";
echo "encode: last_error=", json_last_error(), "\n";
--EXPECT--
decode: JsonException code=4
NULL
decode: last_error=4
encode: JsonException code=5
false
encode: last_error=5

