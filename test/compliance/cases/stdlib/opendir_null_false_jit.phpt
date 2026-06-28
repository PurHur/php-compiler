--TEST--
stdlib opendir(null/empty) — false without E_WARNING JIT (#13344, ext/standard/dir.c)
--JIT--
--FILE--
<?php
$fail = 0;
foreach ([null, ''] as $path) {
    $h = opendir($path);
    if (false !== $h) {
        echo "unexpected\n";
        ++$fail;
    }
}
echo 0 === $fail ? "ok\n" : "fail\n";
--EXPECT--
ok
