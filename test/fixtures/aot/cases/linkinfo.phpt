--TEST--
AOT: linkinfo() — st_dev from lstat on symlink (#6083)
--FILE--
<?php
$link = 'test/compliance/cases/stdlib/is_link_fixture/link';
$i1 = linkinfo($link);
$i2 = linkinfo($link);
echo ($i1 > 0 && $i1 === $i2) ? 'ok' : 'fail', "\n";
if (linkinfo('/no/such/phpc-linkinfo-path')) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
--EXPECT--
ok
gone
