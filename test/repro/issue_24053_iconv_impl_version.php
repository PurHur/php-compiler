<?php
// ICONV_IMPL / ICONV_VERSION identity (#24053) — CharsetEngine is pure-PHP (not glibc).
foreach (['ICONV_IMPL', 'ICONV_VERSION', 'ICONV_MIME_DECODE_STRICT'] as $c) {
    if (!defined($c)) {
        echo $c, "\tUNDEF\n";
        continue;
    }
    $v = constant($c);
    if ('ICONV_IMPL' === $c) {
        echo $c, "\t", is_string($v) && $v !== '' ? $v : 'bad', "\n";
    } elseif ('ICONV_VERSION' === $c) {
        echo $c, "\t", is_string($v) && $v !== '' ? $v : 'bad', "\n";
    } else {
        echo $c, "\t", var_export($v, true), "\n";
    }
}
