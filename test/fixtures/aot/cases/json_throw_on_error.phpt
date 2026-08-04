--TEST--
AOT json_decode/json_encode JSON_THROW_ON_ERROR catchable (#27623)
--FILE--
<?php
try {
    json_decode('{', false, 512, JSON_THROW_ON_ERROR);
    echo "decode: no_throw\n";
} catch (JsonException $e) {
    echo "decode: JsonException code=", $e->getCode(), "\n";
}
try {
    json_encode("\xB1\x31", JSON_THROW_ON_ERROR);
    echo "encode: no_throw\n";
} catch (JsonException $e) {
    echo "encode: JsonException code=", $e->getCode(), "\n";
}
--EXPECT--
decode: JsonException code=4
encode: JsonException code=5
