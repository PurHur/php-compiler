--TEST--
stdlib fileatime() / filectime() / fileinode()
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
$a1 = fileatime($path);
$a2 = fileatime($path);
$c1 = filectime($path);
$c2 = filectime($path);
$i1 = fileinode($path);
$i2 = fileinode($path);
$m = filemtime($path);
if ($a1 === false || $a2 === false || $a1 !== $a2) {
    echo 'atime fail', "\n";
} elseif ($c1 === false || $c2 === false || $c1 !== $c2) {
    echo 'ctime fail', "\n";
} elseif ($i1 === false || $i2 === false || $i1 !== $i2 || $i1 <= 0) {
    echo 'inode fail', "\n";
} elseif ($m === false) {
    echo 'mtime fail', "\n";
} else {
    echo 'ok', "\n";
}
--EXPECT--
ok
