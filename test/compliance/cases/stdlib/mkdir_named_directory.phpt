--TEST--
stdlib mkdir() directory:/permissions:/recursive: named parameters (#12376, ext/standard/filestat.c)
--FILE--
<?php
$dir = 'test/compliance/cases/stdlib/mkdir_fixture/named_' . getmypid();
@rmdir($dir);
if (mkdir(directory: $dir, permissions: 0755, recursive: false) && is_dir($dir)) {
    echo "ok\n";
} else {
    echo "fail\n";
}
@rmdir($dir);
--EXPECT--
ok
