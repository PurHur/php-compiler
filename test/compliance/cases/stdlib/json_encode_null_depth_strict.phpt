--TEST--
stdlib json_encode() null $depth under strict_types — TypeError (#30486, ext/json/json.c)
--FILE--
<?php
declare(strict_types=1);

try {
    json_encode([], 0, null);
    echo "soft: uncaught\n";
} catch (TypeError $e) {
    echo 'soft: ', $e->getMessage(), "\n";
}

try {
    json_encode([], JSON_THROW_ON_ERROR, null);
    echo "throw: uncaught\n";
} catch (TypeError $e) {
    echo 'throw: ', $e->getMessage(), "\n";
} catch (JsonException $e) {
    echo 'throw: JsonException ', $e->getMessage(), "\n";
}
?>
--EXPECT--
soft: json_encode(): Argument #3 ($depth) must be of type int, null given
throw: json_encode(): Argument #3 ($depth) must be of type int, null given
