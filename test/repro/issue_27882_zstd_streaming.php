<?php

/**
 * Issue #27882 — zstd constants + streaming + optional dict arity when advertised.
 */
echo 'ext=', extension_loaded('zstd') ? 'Y' : 'N', "\n";
foreach (['zstd_compress', 'zstd_compress_init', 'zstd_compress_add', 'zstd_uncompress_init', 'zstd_uncompress_add', 'zstd_compress_dict'] as $f) {
    echo $f.'=', function_exists($f) ? 'Y' : 'N', "\n";
}
foreach (['ZSTD_COMPRESS_LEVEL_MIN', 'ZSTD_COMPRESS_LEVEL_MAX', 'ZSTD_COMPRESS_LEVEL_DEFAULT'] as $c) {
    echo $c.'=', defined($c) ? (string) constant($c) : 'N', "\n";
}

$plain = 'hello zstd streaming';
$cctx = zstd_compress_init(ZSTD_COMPRESS_LEVEL_DEFAULT);
$chunk = zstd_compress_add($cctx, $plain, true);
echo 'comp_ok=', (false !== $chunk && '' !== $chunk) ? 'Y' : 'N', "\n";
$uctx = zstd_uncompress_init();
$out = zstd_uncompress_add($uctx, $chunk);
echo 'roundtrip=', ($out === $plain) ? 'Y' : 'N', "\n";
$three = zstd_compress($plain, ZSTD_COMPRESS_LEVEL_DEFAULT, null);
echo 'three_arg=', (false !== $three && is_string($three)) ? 'Y' : 'N', "\n";
