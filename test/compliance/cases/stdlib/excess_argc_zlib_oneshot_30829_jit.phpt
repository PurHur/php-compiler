--TEST--
stdlib: zlib one-shot/file helpers ArgumentCountError wording JIT (#30829)
--FILE--
<?php
$cases = [
    'gzcompress' => static fn () => gzcompress('a', -1, ZLIB_ENCODING_DEFLATE, 1),
    'gzdeflate' => static fn () => gzdeflate('a', -1, ZLIB_ENCODING_RAW, 1),
    'gzencode' => static fn () => gzencode('a', -1, FORCE_GZIP, 1),
    'gzinflate' => static fn () => gzinflate(gzdeflate('a'), 0, 1),
    'gzdecode' => static fn () => gzdecode(gzencode('a'), 0, 1),
    'gzuncompress' => static fn () => gzuncompress(gzcompress('a'), 0, 1),
    'zlib_encode' => static fn () => zlib_encode('a', ZLIB_ENCODING_GZIP, -1, 1),
    'zlib_decode' => static fn () => zlib_decode(zlib_encode('a', ZLIB_ENCODING_DEFLATE), 0, 1),
    'gzfile' => static fn () => gzfile(__FILE__, 0, 1),
    'gzopen' => static fn () => gzopen(__FILE__, 'r', 0, 1),
    'readgzfile' => static fn () => readgzfile(__FILE__, 0, 1),
];
foreach ($cases as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo 'ok=', ('hello' === gzuncompress(gzcompress('hello'))) ? '1' : '0', "\n";
--EXPECT--
gzcompress ArgumentCountError: gzcompress() expects at most 3 arguments, 4 given
gzdeflate ArgumentCountError: gzdeflate() expects at most 3 arguments, 4 given
gzencode ArgumentCountError: gzencode() expects at most 3 arguments, 4 given
gzinflate ArgumentCountError: gzinflate() expects at most 2 arguments, 3 given
gzdecode ArgumentCountError: gzdecode() expects at most 2 arguments, 3 given
gzuncompress ArgumentCountError: gzuncompress() expects at most 2 arguments, 3 given
zlib_encode ArgumentCountError: zlib_encode() expects at most 3 arguments, 4 given
zlib_decode ArgumentCountError: zlib_decode() expects at most 2 arguments, 3 given
gzfile ArgumentCountError: gzfile() expects at most 2 arguments, 3 given
gzopen ArgumentCountError: gzopen() expects at most 3 arguments, 4 given
readgzfile ArgumentCountError: readgzfile() expects at most 2 arguments, 3 given
ok=1
