<?php

declare(strict_types=1);

// Issue #19112 — gzencode/gzdeflate/gzcompress(null) under declare(strict_types=1) call edge (ext/zlib/zlib.c).
// Issue #19119 — zlib decompress/read helpers reject null under declare(strict_types=1) caller.

$checks = [
    'gzencode' => static fn () => gzencode(null),
    'gzdeflate' => static fn () => gzdeflate(null),
    'gzcompress' => static fn () => gzcompress(null),
    'gzuncompress' => static fn () => gzuncompress(null),
    'gzdecode' => static fn () => gzdecode(null),
    'gzinflate' => static fn () => gzinflate(null),
    'zlib_encode' => static fn () => zlib_encode(null, \ZLIB_ENCODING_GZIP),
    'gzfile' => static fn () => gzfile(null),
    'readgzfile' => static fn () => readgzfile(null),
];

foreach ($checks as $name => $fn) {
    try {
        $fn();
        fwrite(STDERR, "FAIL:$name:no_error\n");
        exit(1);
    } catch (\TypeError) {
        // expected
    }
}

echo "strict_edge_ok\n";
