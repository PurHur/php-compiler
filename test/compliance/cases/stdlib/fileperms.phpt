--TEST--
stdlib fileperms()
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
$p = fileperms($path);
$p2 = fileperms($path);
if ($p === false || $p2 === false || $p !== $p2) {
    echo "fail\n";
} else {
    echo "ok\n";
}
--EXPECT--
ok
