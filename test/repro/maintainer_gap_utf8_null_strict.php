<?php

declare(strict_types=1);

/**
 * #29889 — utf8_encode/utf8_decode(null) under strict_types → TypeError ($string).
 * php-src: ext/standard/utf8.c / basic_functions.stub.php (re-#18591)
 *
 * Echo the call (expression use) so AOT try/catch TypeError IR verifies.
 */
try {
    echo utf8_encode(null), "\n";
    echo "bad:utf8_encode:uncaught\n";
} catch (TypeError $e) {
    echo "ok:utf8_encode:TypeError:", $e->getMessage(), "\n";
}

try {
    echo utf8_decode(null), "\n";
    echo "bad:utf8_decode:uncaught\n";
} catch (TypeError $e) {
    echo "ok:utf8_decode:TypeError:", $e->getMessage(), "\n";
}
