<?php

/**
 * Repro #30829 — zlib one-shot / file helpers excess argc → ArgumentCountError.
 * php-src: ext/zlib/zlib.c
 */
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
        echo $name, ":NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
$round = gzuncompress(gzcompress('hello'));
echo 'ok=', ('hello' === $round) ? '1' : '0', "\n";
