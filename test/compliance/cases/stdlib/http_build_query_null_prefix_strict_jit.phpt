--TEST--
http_build_query(null $numeric_prefix) TypeError under strict_types JIT (#29721, ext/standard/http.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    http_build_query(['a' => 1], null);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: http_build_query(): Argument #2 ($numeric_prefix) must be of type string, null given
