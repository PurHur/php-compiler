--TEST--
stdlib touch(null/empty) — false without E_WARNING (#13343, ext/standard/filestat.c)
--FILE--
<?php
$fail = 0;
foreach ([null, ''] as $path) {
    $ok = touch($path);
    if (false !== $ok) {
        echo "unexpected\n";
        ++$fail;
    }
}
echo 0 === $fail ? "ok\n" : "fail\n";
--EXPECT--
ok
