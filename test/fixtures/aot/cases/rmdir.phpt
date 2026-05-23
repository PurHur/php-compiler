--TEST--
AOT: rmdir() removes empty directory via libc rmdir(2)
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/rmdir_fixture';
$dir = $base . '/aot_one';
if (!is_dir($base)) {
    mkdir($base, 0777, true);
}
if (mkdir($dir)) {
    if (rmdir($dir)) {
        echo "ok\n";
    } else {
        echo "fail\n";
    }
} else {
    echo "setup\n";
}
if (rmdir($dir)) {
    echo "bad\n";
} else {
    echo "gone\n";
}
--EXPECT--
ok
gone
