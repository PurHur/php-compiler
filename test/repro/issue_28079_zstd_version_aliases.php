<?php
/** Issue #28079 — ZSTD_VERSION_* + Zstd\compress aliases when zstd advertised. */
echo 'ext=', extension_loaded('zstd') ? '1' : '0', PHP_EOL;
foreach (['ZSTD_VERSION_TEXT', 'ZSTD_VERSION_NUMBER'] as $c) {
    echo $c, '=', defined($c) ? '1' : '0', PHP_EOL;
}
if (defined('ZSTD_VERSION_TEXT')) {
    echo 'text_nonempty=', ZSTD_VERSION_TEXT !== '' ? '1' : '0', PHP_EOL;
}
if (defined('ZSTD_VERSION_NUMBER')) {
    echo 'number_pos=', ZSTD_VERSION_NUMBER > 0 ? '1' : '0', PHP_EOL;
}
foreach (['Zstd\\compress', 'Zstd\\uncompress', 'Zstd\\compress_init', 'Zstd\\uncompress_init'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', PHP_EOL;
}
$plain = 'zstd-alias-roundtrip';
$c = \Zstd\compress($plain);
$u = \Zstd\uncompress($c);
echo 'round=', ($c !== false && $u === $plain) ? '1' : '0', PHP_EOL;
