--TEST--
AOT: DirectoryIterator isFile/isDir + getFilename (#33263)
--FILE--
<?php
$dir = 'test/fixtures/aot/cases/directoryiterator_27289_fixture';
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    if ($f->isFile()) {
        echo 'file:', $f->getFilename(), "\n";
    }
    if ($f->isDir()) {
        echo 'dir:', $f->getFilename(), "\n";
    }
}
--EXPECT--
file:a.txt
