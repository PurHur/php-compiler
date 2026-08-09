<?php
// #29345 — json_encode depth≤0: Zend returns false + JSON_ERROR_DEPTH (not ValueError).
$r = json_encode([], 0, 0);
echo '[] depth0 => ', var_export($r, true),
    ' err=', json_last_error(), ' ', json_last_error_msg(), "\n";
$r = json_encode([], 0, -1);
echo '[] depth-1 => ', var_export($r, true),
    ' err=', json_last_error(), ' ', json_last_error_msg(), "\n";
$r = json_encode(1, 0, 0);
echo 'scalar depth0 => ', var_export($r, true),
    ' err=', json_last_error(), ' ', json_last_error_msg(), "\n";
try {
    json_encode([], JSON_THROW_ON_ERROR, 0);
    echo "THROW unexpected success\n";
} catch (JsonException $e) {
    echo 'THROW ', $e->getMessage(), ' code=', $e->getCode(),
        ' sticky=', json_last_error(), "\n";
}
try {
    json_decode('[]', true, 0);
    echo "decode depth0 unexpected\n";
} catch (ValueError $e) {
    echo 'decode depth0 ValueError ok', "\n";
}
