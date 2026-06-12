--TEST--
stdlib hash_file() / hash_hmac_file() — file digest parity (ext/hash/hash.c, #3221)
--FILE--
<?php
declare(strict_types=1);

$path = sys_get_temp_dir() . '/hash_file_test_' . getmypid() . '.txt';
file_put_contents($path, 'hello');
$algos = hash_algos();
echo in_array('sha256', $algos, true) ? "algos_ok\n" : "algos_fail\n";
echo hash_file('sha256', $path), "\n";
echo hash_hmac_file('sha256', $path, 'key'), "\n";
unlink($path);
--EXPECT--
algos_ok
2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824
9307b3b915efb5171ff14d8cb55fbcc798c6c0ef1456d66ded1a6aa723a58b7b
