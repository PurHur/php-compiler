<?php
/** Repro #29069 — json_validate accepts only 0|JSON_INVALID_UTF8_IGNORE (php-src ext/json/json.c). */
$bad = "\"\x80\"";
echo 'flags=0 => ', json_validate($bad, 512, 0) ? 'true' : 'false', "\n";
echo 'flags=', (string) JSON_INVALID_UTF8_IGNORE, ' => ', json_validate($bad, 512, JSON_INVALID_UTF8_IGNORE) ? 'true' : 'false', "\n";
try {
    json_validate($bad, 512, JSON_INVALID_UTF8_SUBSTITUTE);
    echo 'flags=', (string) JSON_INVALID_UTF8_SUBSTITUTE, ' => true', "\n";
} catch (Throwable $e) {
    echo 'flags=', (string) JSON_INVALID_UTF8_SUBSTITUTE, ' => ', get_class($e), ':', $e->getMessage(), "\n";
}
$both = JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE;
try {
    json_validate($bad, 512, $both);
    echo 'flags=', (string) $both, ' => true', "\n";
} catch (Throwable $e) {
    echo 'flags=', (string) $both, ' => ', get_class($e), ':', $e->getMessage(), "\n";
}
