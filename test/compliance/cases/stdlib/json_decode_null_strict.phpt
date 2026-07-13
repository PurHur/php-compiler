--TEST--
stdlib json_decode() null $json TypeError under declare(strict_types=1) (#18665, ext/json/php_json.c)
--FILE--
<?php
declare(strict_types=1);

try {
    json_decode(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
json_decode(): Argument #1 ($json) must be of type string, null given
