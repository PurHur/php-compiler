<?php

/**
 * Issue #27856 — brotli streaming + BROTLI_* constants when brotli advertised.
 */
echo 'ext=', extension_loaded('brotli') ? 'Y' : 'N', "\n";
foreach (['brotli_compress', 'brotli_compress_init', 'brotli_compress_add', 'brotli_uncompress_init', 'brotli_uncompress_add'] as $f) {
    echo $f.'=', function_exists($f) ? 'Y' : 'N', "\n";
}
foreach (['BROTLI_GENERIC', 'BROTLI_TEXT', 'BROTLI_FONT', 'BROTLI_COMPRESS_LEVEL_DEFAULT', 'BROTLI_FLUSH', 'BROTLI_FINISH'] as $c) {
    echo $c.'=', defined($c) ? (string) constant($c) : 'N', "\n";
}

$plain = 'hello brotli streaming';
$cctx = brotli_compress_init(BROTLI_COMPRESS_LEVEL_DEFAULT, BROTLI_GENERIC);
$chunk = brotli_compress_add($cctx, $plain, BROTLI_FINISH);
echo 'comp_ok=', (false !== $chunk && '' !== $chunk) ? 'Y' : 'N', "\n";

$uctx = brotli_uncompress_init();
$out = brotli_uncompress_add($uctx, $chunk, BROTLI_FINISH);
echo 'roundtrip=', ($out === $plain) ? 'Y' : 'N', "\n";
