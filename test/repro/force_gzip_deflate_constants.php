<?php
declare(strict_types=1);

// #24052 — FORCE_GZIP / FORCE_DEFLATE aliases of ZLIB_ENCODING_*
foreach (['FORCE_GZIP', 'FORCE_DEFLATE', 'ZLIB_ENCODING_GZIP', 'ZLIB_ENCODING_DEFLATE'] as $c) {
    echo $c, "\t", defined($c) ? var_export(constant($c), true) : 'UNDEF', "\n";
}
echo 'alias_gzip=', defined('FORCE_GZIP') && FORCE_GZIP === ZLIB_ENCODING_GZIP ? '1' : '0', "\n";
echo 'alias_deflate=', defined('FORCE_DEFLATE') && FORCE_DEFLATE === ZLIB_ENCODING_DEFLATE ? '1' : '0', "\n";
$bin = gzencode('hi', -1, FORCE_GZIP);
echo 'gzencode_magic=', false === $bin ? 'fail' : bin2hex(substr($bin, 0, 2)), "\n";
$cats = get_defined_constants(true);
echo 'bucket=', isset($cats['zlib']['FORCE_GZIP']) ? 'zlib' : (isset($cats['standard']['FORCE_GZIP']) ? 'standard' : 'missing'), "\n";
