<?php
// OPENSSL_VERSION_TEXT / OPENSSL_VERSION_NUMBER / OPENSSL_DEFAULT_STREAM_CIPHERS (#24070)
foreach (['OPENSSL_VERSION_TEXT', 'OPENSSL_VERSION_NUMBER', 'OPENSSL_DEFAULT_STREAM_CIPHERS', 'OPENSSL_RAW_DATA'] as $c) {
    if (!defined($c)) {
        echo $c, "\tUNDEF\n";
        continue;
    }
    $v = constant($c);
    if ('OPENSSL_VERSION_TEXT' === $c) {
        echo $c, "\t", is_string($v) && str_starts_with($v, 'OpenSSL ') ? 'ok' : 'bad', "\n";
    } elseif ('OPENSSL_VERSION_NUMBER' === $c) {
        echo $c, "\t", is_int($v) && $v > 0x10000000 ? 'ok' : 'bad', "\n";
    } elseif ('OPENSSL_DEFAULT_STREAM_CIPHERS' === $c) {
        echo $c, "\t", is_string($v) && str_contains($v, 'AES128-GCM-SHA256') ? 'ok' : 'bad', "\n";
    } else {
        echo $c, "\t", var_export($v, true), "\n";
    }
}
