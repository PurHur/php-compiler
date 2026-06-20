--TEST--
AOT: linkinfo() — st_dev from lstat on symlink (#6083)
--FILE--
<?php
$link = 'test/compliance/cases/stdlib/is_link_fixture/link';
$i1 = linkinfo($link);
$i2 = linkinfo($link);
echo ($i1 > 0 && $i1 === $i2) ? 'ok' : 'fail', "\n";
if (linkinfo('/no/such/phpc-linkinfo-path') === -1) {
    echo 'gone', "\n";
} else {
    echo 'bad', "\n";
}
--EXPECT--
PHP Warning:  linkinfo(): No such file or directory
ok
gone
