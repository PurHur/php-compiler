<?php
/**
 * Issue #21569 — curl_multi_select() must accept float timeout (TYPE_FLOAT, not TYPE_DOUBLE).
 * php-src: ext/curl/multi.c PHP_FUNCTION(curl_multi_select)
 */
declare(strict_types=1);

$failed = 0;
$mh = curl_multi_init();

try {
    $r = curl_multi_select($mh, 0.01);
    if (!is_int($r)) {
        echo "float timeout: expected int, got ", gettype($r), "\n";
        ++$failed;
    }
} catch (Throwable $e) {
    echo "float timeout: ", get_class($e), ':', $e->getMessage(), "\n";
    ++$failed;
}

try {
    $r = curl_multi_select($mh);
    if (!is_int($r)) {
        echo "default timeout: expected int, got ", gettype($r), "\n";
        ++$failed;
    }
} catch (Throwable $e) {
    echo "default timeout: ", get_class($e), ':', $e->getMessage(), "\n";
    ++$failed;
}

try {
    $r = curl_multi_select($mh, 0);
    if (!is_int($r)) {
        echo "int timeout: expected int, got ", gettype($r), "\n";
        ++$failed;
    }
} catch (Throwable $e) {
    echo "int timeout: ", get_class($e), ':', $e->getMessage(), "\n";
    ++$failed;
}

try {
    curl_multi_select();
    echo "arity0 uncaught\n";
    ++$failed;
} catch (ArgumentCountError $e) {
    // ok
}

try {
    curl_multi_select($mh, 0.01, 1);
    echo "arity3 uncaught\n";
    ++$failed;
} catch (ArgumentCountError $e) {
    // ok
}

curl_multi_close($mh);
echo $failed > 0 ? "FAIL\n" : "ok\n";
exit($failed > 0 ? 1 : 0);
