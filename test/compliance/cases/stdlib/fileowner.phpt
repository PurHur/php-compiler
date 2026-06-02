--TEST--
stdlib fileowner() / filegroup()
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
if (fileowner('/no/such/phpc-fileowner-path')) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
--EXPECT--
ok
gone
