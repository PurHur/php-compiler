--TEST--
stdlib simdjson_decode/simdjson_is_valid MVP (#22530, PECL simdjson)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsSimdjson()) {
    die('skip simdjson withheld on reference profile (#22530)');
}
--FILE--
<?php
declare(strict_types=1);

echo simdjson_is_valid('{"a":1}') ? '1' : '0';
echo simdjson_is_valid('{') ? '1' : '0';
$decoded = simdjson_decode('{"a":1}', true);
echo ($decoded === ['a' => 1]) ? '1' : '0';
$obj = simdjson_decode('{"a":1}');
echo (is_object($obj) && 1 === $obj->a) ? '1' : '0';
echo function_exists('simdjson_decode') ? '1' : '0';
echo function_exists('simdjson_is_valid') ? '1' : '0';
echo extension_loaded('simdjson') ? '1' : '0';
echo "\n";
try {
    simdjson_decode('{');
    echo "no_throw\n";
} catch (SimdJsonException $e) {
    echo "throw\n";
}
?>
--EXPECT--
1011111
throw
