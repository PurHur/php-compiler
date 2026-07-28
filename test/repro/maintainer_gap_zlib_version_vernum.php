<?php
declare(strict_types=1);

// #24072 — ZLIB_VERSION / ZLIB_VERNUM (ext/zlib/zlib.c)
foreach (['ZLIB_VERSION', 'ZLIB_VERNUM', 'ZLIB_ENCODING_GZIP'] as $c) {
    echo $c, "\t", defined($c) ? var_export(constant($c), true) : 'UNDEF', "\n";
}
