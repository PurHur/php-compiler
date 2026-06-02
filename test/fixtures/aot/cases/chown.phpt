--TEST--
AOT: chown() sets owner via libc chown(2)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/chmod_fixture/chown_aot_data.txt';
if (file_put_contents($path, 'x')) {
    $st = stat($path);
    $uid = (int) ($st['uid'] ?? 0);
    if (chown($path, $uid)) {
        echo "ok\n";
    } else {
        echo "fail\n";
    }
} else {
    echo "setup\n";
}
--EXPECT--
ok
