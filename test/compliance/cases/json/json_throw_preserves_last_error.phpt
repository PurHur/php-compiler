--TEST--
json JSON_THROW_ON_ERROR preserves json_last_error (#25456, ext/json/json.c)
--FILE--
<?php

try {
    json_decode('{', false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    echo 'decode_throw code=', $e->getCode(), "\n";
}
echo 'after_clean_decode=', json_last_error(), '|', json_last_error_msg(), "\n";

try {
    json_encode(NAN, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    echo 'encode_throw code=', $e->getCode(), "\n";
}
echo 'after_clean_encode=', json_last_error(), '|', json_last_error_msg(), "\n";

json_decode('{');
echo 'soft_decode=', json_last_error(), "\n";
try {
    json_decode('{', false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
}
echo 'after_soft_then_throw_decode=', json_last_error(), "\n";

json_encode(NAN);
echo 'soft_encode=', json_last_error(), "\n";
try {
    json_encode(NAN, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
}
echo 'after_soft_then_throw_encode=', json_last_error(), "\n";

json_decode('null');
echo 'ok_decode=', json_last_error(), "\n";
try {
    json_decode('{', false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
}
echo 'after_ok_then_throw=', json_last_error(), "\n";
--EXPECT--
decode_throw code=4
after_clean_decode=0|No error
encode_throw code=7
after_clean_encode=0|No error
soft_decode=4
after_soft_then_throw_decode=4
soft_encode=7
after_soft_then_throw_encode=7
ok_decode=0
after_ok_then_throw=0
