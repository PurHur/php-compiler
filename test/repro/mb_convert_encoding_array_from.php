<?php
// Repro #23562 — mb_convert_encoding() array / comma-list $from_encoding (php-src ext/mbstring/mbstring.c)
$bytes = "\xE9";
var_export(mb_convert_encoding($bytes, 'UTF-8', ['UTF-8', 'ISO-8859-1']));
echo "\n";
var_export(mb_convert_encoding($bytes, 'UTF-8', ['ISO-8859-1', 'UTF-8']));
echo "\n";
var_export(mb_convert_encoding($bytes, 'UTF-8', 'UTF-8,ISO-8859-1'));
echo "\n";
try {
    mb_convert_encoding($bytes, 'UTF-8', ['nope']);
    echo "no_exception\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    mb_convert_encoding($bytes, 'UTF-8', []);
    echo "no_exception\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$bad = "\xFF\xFE";
var_export(@mb_convert_encoding($bad, 'UTF-8', ['ASCII', 'UTF-8']));
echo "\n";
