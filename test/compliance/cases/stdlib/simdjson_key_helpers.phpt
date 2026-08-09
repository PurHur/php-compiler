--TEST--
stdlib simdjson_key_exists/count/value (#27857)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!\PHPCompiler\ext\simdjson\SimdjsonExtensionPolicy::advertisesExtension()) {
    die('skip simdjson withheld (#22530)');
}
?>
--FILE--
<?php
declare(strict_types=1);
$json = '{"a":{"b":[1,2,3]}}';
foreach (['simdjson_key_exists','simdjson_key_count','simdjson_key_value'] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}
echo 'exists=', simdjson_key_exists($json, '/a/b') ? 'Y' : 'N', "\n";
echo 'missing=', simdjson_key_exists($json, '/z') ? 'Y' : 'N', "\n";
echo 'count=', simdjson_key_count($json, '/a/b'), "\n";
echo 'value=', var_export(simdjson_key_value($json, '/a/b/0'), true), "\n";
echo 'class=', class_exists('SimdJsonValueError') ? 'Y' : 'N', "\n";
?>
--EXPECT--
simdjson_key_exists=Y
simdjson_key_count=Y
simdjson_key_value=Y
exists=Y
missing=N
count=3
value=1
class=Y
