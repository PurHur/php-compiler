<?php
/**
 * Repro #27623 — AOT json_decode/json_encode + JSON_THROW_ON_ERROR.
 * Expect: JsonException (matching Zend/VM), not compile abort.
 */
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
