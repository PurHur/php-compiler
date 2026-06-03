--TEST--
JIT: fileowner() / filegroup() via stat st_uid/st_gid
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
$o1 = fileowner($path);
$o2 = fileowner($path);
$g1 = filegroup($path);
$g2 = filegroup($path);
if ($o1 === false || $o2 === false || $g1 === false || $g2 === false || $o1 !== $o2 || $g1 !== $g2) {
    echo 'fail', "\n";
} else {
    echo 'ok', "\n";
}
--EXPECT--
ok
