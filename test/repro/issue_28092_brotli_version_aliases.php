<?php
/** Issue #28092 — BROTLI_VERSION_* + Brotli\compress aliases when brotli advertised. */
echo 'ext=', extension_loaded('brotli') ? '1' : '0', PHP_EOL;
foreach (['BROTLI_VERSION_TEXT', 'BROTLI_VERSION_NUMBER', 'BROTLI_DICTIONARY_SUPPORT'] as $c) {
    echo $c, '=', defined($c) ? '1' : '0', PHP_EOL;
}
if (defined('BROTLI_VERSION_TEXT')) {
    echo 'text_nonempty=', BROTLI_VERSION_TEXT !== '' ? '1' : '0', PHP_EOL;
}
if (defined('BROTLI_VERSION_NUMBER')) {
    echo 'number_pos=', BROTLI_VERSION_NUMBER > 0 ? '1' : '0', PHP_EOL;
}
echo 'dict=', defined('BROTLI_DICTIONARY_SUPPORT') ? (BROTLI_DICTIONARY_SUPPORT ? '1' : '0') : 'x', PHP_EOL;
foreach (['Brotli\\compress', 'Brotli\\uncompress', 'Brotli\\compress_init', 'Brotli\\uncompress_init', 'Brotli\\compress_add', 'Brotli\\uncompress_add'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', PHP_EOL;
}
$plain = 'alias-roundtrip';
$c = \Brotli\compress($plain);
$u = \Brotli\uncompress($c);
echo 'round=', ($c !== false && $u === $plain) ? '1' : '0', PHP_EOL;
