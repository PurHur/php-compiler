--TEST--
Language: object array offset — array_key_exists/isset/empty/key_exists throw TypeError (#6500, #29549)
--FILE--
<?php
class K {}

$a = [];

try {
    var_export(array_key_exists(new K, $a));
} catch (Throwable $e) {
    echo 'array_key_exists: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    var_export(key_exists(new K, $a));
} catch (Throwable $e) {
    echo 'key_exists: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    var_export(isset($a[new K]));
} catch (Throwable $e) {
    echo 'isset: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    var_export(empty($a[new K]));
} catch (Throwable $e) {
    echo 'empty: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $a = ['x' => 1];
    var_export(isset($a['x']));
} catch (Throwable $e) {
    echo 'string_key: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
array_key_exists: TypeError: Illegal offset type
key_exists: TypeError: Illegal offset type
isset: TypeError: Illegal offset type in isset or empty
empty: TypeError: Illegal offset type in isset or empty
true
