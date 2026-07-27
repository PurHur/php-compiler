<?php

declare(strict_types=1);

/**
 * Issue #23642 — inflate_init() accepts 2nd $options; Reflection names encoding/options.
 * php-src: ext/zlib/zlib.stub.php
 */

$rf = new ReflectionFunction('inflate_init');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'params=', implode(',', $names), "\n";

$ctx = inflate_init(ZLIB_ENCODING_GZIP, []);
echo 'positional=', get_debug_type($ctx), "\n";

$named = inflate_init(encoding: ZLIB_ENCODING_GZIP, options: []);
echo 'named=', get_debug_type($named), "\n";

try {
    inflate_init(ZLIB_ENCODING_GZIP, ['window' => 7]);
    echo "window7=ok\n";
} catch (Throwable $e) {
    echo 'window7=', get_class($e), ':', $e->getMessage(), "\n";
}
