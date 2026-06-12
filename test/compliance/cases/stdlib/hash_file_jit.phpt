--TEST--
stdlib hash_file() — JIT file digest (#3221)
--JIT--
--FILE--
<?php
declare(strict_types=1);

$path = sys_get_temp_dir() . '/hash_file_jit_' . getmypid() . '.txt';
file_put_contents($path, 'hello');
echo hash_file('sha256', $path), "\n";
unlink($path);
--EXPECT--
2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824
