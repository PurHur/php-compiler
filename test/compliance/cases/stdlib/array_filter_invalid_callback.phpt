--TEST--
stdlib array_filter() invalid callback TypeError (#10782, ext/standard/array.c)
--FILE--
<?php
$a = ['keep' => 1, 'drop' => 2];

try {
    array_filter($a, ARRAY_FILTER_USE_KEY);
    echo "uncaught flag-as-callback\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    array_filter($a, ARRAY_FILTER_USE_BOTH);
    echo "uncaught both-flag-as-callback\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    array_filter($a, true);
    echo "uncaught bool-callback\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    array_filter($a, [1]);
    echo "uncaught short-array-callback\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    array_filter($a, 'not_a_real_function_xyz');
    echo "uncaught undefined-string-callback\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$out = array_filter(
    $a,
    fn ($k) => $k === 'keep',
    ARRAY_FILTER_USE_KEY
);
var_export($out);
echo "\n";
--EXPECT--
array_filter(): Argument #2 ($callback) must be a valid callback or null, no array or string given
array_filter(): Argument #2 ($callback) must be a valid callback or null, no array or string given
array_filter(): Argument #2 ($callback) must be a valid callback or null, no array or string given
array_filter(): Argument #2 ($callback) must be a valid callback or null, array callback must have exactly two members
array_filter(): Argument #2 ($callback) must be a valid callback or null, function "not_a_real_function_xyz" not found or invalid function name
array (
  'keep' => 1,
)
