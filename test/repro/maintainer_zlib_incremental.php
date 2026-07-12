<?php

declare(strict_types=1);

$ctx = deflate_init(ZLIB_ENCODING_RAW);
if (!$ctx instanceof DeflateContext) {
    echo "fail: deflate context type\n";
    exit(1);
}
$out = deflate_add($ctx, 'hello', ZLIB_FINISH);
if (!\is_string($out) || '' === $out) {
    echo "fail: deflate output\n";
    exit(1);
}
$in = inflate_init(ZLIB_ENCODING_RAW);
if (!$in instanceof InflateContext) {
    echo "fail: inflate context type\n";
    exit(1);
}
$plain = inflate_add($in, $out, ZLIB_FINISH);
if ('hello' !== $plain) {
    echo "fail: round-trip got ", var_export($plain, true), "\n";
    exit(1);
}
echo "zlib_incremental_ok=1\n";
