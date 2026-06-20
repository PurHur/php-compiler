--TEST--
stdlib linkinfo() — symlink st_dev via lstat (#6083, ext/standard/link.c)
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $base . '/link';
$lstat = lstat($link);
$info = linkinfo($link);
echo ($lstat !== false && $info !== false && $info === $lstat['dev']) ? 'ok' : 'fail', "\n";
if (linkinfo('/no/such/phpc-linkinfo-path') === -1) {
    echo 'gone', "\n";
} else {
    echo 'bad', "\n";
}
--EXPECT--
PHP Warning:  linkinfo(): No such file or directory
ok
gone
