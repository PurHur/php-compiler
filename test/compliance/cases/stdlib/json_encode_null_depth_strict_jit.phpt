--TEST--
stdlib json_encode() null $depth under strict_types JIT — TypeError (#30486, ext/json/json.c)
--FILE--
<?php
declare(strict_types=1);
$soft = false;
$throw = false;
try {
    json_encode([], 0, null);
} catch (TypeError $e) {
    $soft = ('json_encode(): Argument #3 ($depth) must be of type int, null given' === $e->getMessage());
}
try {
    json_encode([], JSON_THROW_ON_ERROR, null);
} catch (TypeError $e) {
    $throw = ('json_encode(): Argument #3 ($depth) must be of type int, null given' === $e->getMessage());
} catch (JsonException $e) {
    $throw = false;
}
echo $soft ? "soft TypeError\n" : "soft no TypeError\n";
echo $throw ? "throw TypeError\n" : "throw no TypeError\n";
?>
--EXPECT--
soft TypeError
throw TypeError
