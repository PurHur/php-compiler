--TEST--
zlib FORCE_GZIP / FORCE_DEFLATE encoding aliases (ext/zlib/zlib.c, #24052)
--FILE--
<?php
declare(strict_types=1);

echo defined('FORCE_GZIP') && FORCE_GZIP === 31 ? 'gzip_ok' : 'gzip_bad', "\n";
echo defined('FORCE_DEFLATE') && FORCE_DEFLATE === 15 ? 'deflate_ok' : 'deflate_bad', "\n";
echo FORCE_GZIP === ZLIB_ENCODING_GZIP ? 'alias_gzip' : 'alias_gzip_bad', "\n";
echo FORCE_DEFLATE === ZLIB_ENCODING_DEFLATE ? 'alias_deflate' : 'alias_deflate_bad', "\n";
$bin = gzencode('hi', -1, FORCE_GZIP);
echo false !== $bin && bin2hex(substr($bin, 0, 2)) === '1f8b' ? 'gzencode_ok' : 'gzencode_bad', "\n";
$cats = get_defined_constants(true);
echo isset($cats['zlib']['FORCE_GZIP']) && isset($cats['zlib']['FORCE_DEFLATE']) ? 'bucket_ok' : 'bucket_bad', "\n";
--EXPECT--
gzip_ok
deflate_ok
alias_gzip
alias_deflate
gzencode_ok
bucket_ok
