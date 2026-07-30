<?php
// #25456 — JSON_THROW_ON_ERROR leaves json_last_error unchanged (php-src ext/json/json.c).
// Clean slate → stays 0; after a soft failure → previous code preserved.

try {
    json_decode('{', false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
}
echo json_last_error(), '|', json_last_error_msg(), "\n";

try {
    json_encode(NAN, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
}
echo json_last_error(), '|', json_last_error_msg(), "\n";

json_decode('{');
echo 'soft=', json_last_error(), "\n";
try {
    json_decode('{', false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
}
echo 'after_throw_decode=', json_last_error(), "\n";

json_encode(NAN);
echo 'soft_enc=', json_last_error(), "\n";
try {
    json_encode(NAN, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
}
echo 'after_throw_encode=', json_last_error(), "\n";
