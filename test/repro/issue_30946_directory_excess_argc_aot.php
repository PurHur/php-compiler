<?php

/**
 * AOT repro #30946 — Directory excess argc (static calls; dynamic $d->$m breaks AOT lowering).
 * php-src: ext/standard/dir.c
 *
 * AOT: php bin/compile.php -o /tmp/dir30946 test/repro/issue_30946_directory_excess_argc_aot.php && /tmp/dir30946
 */
$d = dir('/tmp');
try {
    $d->read('x');
    echo "read: OK\n";
} catch (Throwable $e) {
    echo 'read: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $d->rewind('x');
    echo "rewind: OK\n";
} catch (Throwable $e) {
    echo 'rewind: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $d->close('x');
    echo "close: OK\n";
} catch (Throwable $e) {
    echo 'close: ', get_class($e), ': ', $e->getMessage(), "\n";
}
$d2 = dir('/tmp');
$first = $d2->read();
$d2->rewind();
$d2->close();
echo 'ok=', is_string($first) ? '1' : '0', "\n";
