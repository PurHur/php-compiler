--TEST--
json json_decode() rejects null $json (#18601, ext/json/php_json.c)
--FILE--
<?php
declare(strict_types=1);
try {
    json_decode(null);
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: json_decode(): Argument #1 ($json) must be of type string, null given
