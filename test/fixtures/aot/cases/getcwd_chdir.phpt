--TEST--
AOT: getcwd() and chdir() via libc
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/getcwd_chdir_fixture/aot_sub';
if (!is_dir($base)) {
    mkdir($base, 0777, true);
}
$start = getcwd();
if (is_string($start) && chdir($base)) {
    $here = getcwd();
    if (is_string($here) && basename($here) === 'aot_sub') {
        echo "ok\n";
    } else {
        echo "fail\n";
    }
    chdir($start);
} else {
    echo "setup\n";
}
--EXPECT--
ok
