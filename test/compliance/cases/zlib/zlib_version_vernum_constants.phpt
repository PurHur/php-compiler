--TEST--
zlib ZLIB_VERSION / ZLIB_VERNUM identity constants (ext/zlib/zlib.c, #24072)
--FILE--
<?php
declare(strict_types=1);

echo defined('ZLIB_VERSION') ? gettype(constant('ZLIB_VERSION')) : 'undef', "\n";
echo defined('ZLIB_VERNUM') ? gettype(constant('ZLIB_VERNUM')) : 'undef', "\n";
echo is_string(ZLIB_VERSION) && preg_match('/^\d+\.\d+(\.\d+)?/', ZLIB_VERSION) ? 'version_ok' : 'version_bad', "\n";
echo is_int(ZLIB_VERNUM) && ZLIB_VERNUM > 0 ? 'vernum_ok' : 'vernum_bad', "\n";
echo defined('ZLIB_ENCODING_GZIP') && ZLIB_ENCODING_GZIP === 31 ? 'encoding_ok' : 'encoding_bad', "\n";
--EXPECT--
string
integer
version_ok
vernum_ok
encoding_ok
