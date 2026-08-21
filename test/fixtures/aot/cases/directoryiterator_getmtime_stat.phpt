--TEST--
AOT: DirectoryIterator getPerms via stat metadata proxies (#33282)
--FILE--
<?php
$dir = 'test/fixtures/aot/cases/directoryiterator_27289_fixture';
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    if (!$f->isFile()) {
        continue;
    }
    echo 'name:', $f->getFilename(), "\n";
    echo 'perms:', $f->getPerms(), "\n";
}
--EXPECT--
name:a.txt
perms:33188
