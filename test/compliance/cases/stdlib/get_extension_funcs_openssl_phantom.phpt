--TEST--
stdlib get_extension_funcs('standard') omits openssl_* when openssl unloaded (#15045)
--FILE--
<?php
declare(strict_types=1);

if (extension_loaded('openssl')) {
    echo "skip_loaded\n";
    exit(0);
}
$funcs = get_extension_funcs('standard');
echo is_array($funcs) ? "1" : "0";
echo in_array('openssl_encrypt', $funcs, true) ? "1" : "0";
echo get_extension_funcs('openssl') === false ? "1" : "0";
echo "\n";
--EXPECT--
101
