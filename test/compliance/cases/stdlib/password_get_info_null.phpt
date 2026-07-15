--TEST--
stdlib password_get_info(null) — unknown algo like Zend, array operand TypeError (#18656, ext/standard/password.c)
--FILE--
<?php
$info = password_get_info(null);
echo $info['algoName'], "\n";
echo null === $info['algo'] ? "algo_null\n" : "algo_set\n";
try {
    password_get_info(['x']);
    echo "array_uncaught\n";
} catch (TypeError $e) {
    echo 'array TypeError: ', $e->getMessage(), "\n";
}
--EXPECT--
unknown
algo_null
array TypeError: password_get_info(): Argument #1 ($hash) must be of type string, array given
