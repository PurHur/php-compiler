--TEST--
AOT: fileatime() / filectime() / fileinode() via stat
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
$a1 = fileatime($path);
$a2 = fileatime($path);
$c1 = filectime($path);
$c2 = filectime($path);
$i1 = fileinode($path);
$i2 = fileinode($path);
if ($a1 === false || $a2 === false || $a1 !== $a2) {
    echo 'atime fail', "\n";
} elseif ($c1 === false || $c2 === false || $c1 !== $c2) {
    echo 'ctime fail', "\n";
} elseif ($i1 === false || $i2 === false || $i1 !== $i2 || $i1 <= 0) {
    echo 'inode fail', "\n";
} else {
    echo 'ok', "\n";
}
if (fileatime('/no/such/phpc-file-stat-times-path')) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
--EXPECT--
ok
gone
