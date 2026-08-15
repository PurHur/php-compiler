--TEST--
AOT: http_build_query(null $encoding_type) TypeError under strict_types (#31247, ext/standard/http.c)
--FILE--
<?php
declare(strict_types=1);
try {
    http_build_query(['a' => 1], '', '&', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
http_build_query(): Argument #4 ($encoding_type) must be of type int, null given
