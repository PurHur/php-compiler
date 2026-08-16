<?php

/**
 * Issue #31445 — gzcompress/gzencode/gzdeflate null $level E_DEPRECATED + coerce.
 *
 * php-src: ext/zlib/zlib.c PHP_FUNCTION(gzcompress|gzencode|gzdeflate) / int $level = -1
 */
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
foreach (['gzcompress', 'gzencode', 'gzdeflate'] as $f) {
    $r = $f('a', null);
    echo $f, ' ', is_string($r) && strlen($r) > 0 ? 'OK' : 'BAD', "\n";
}
