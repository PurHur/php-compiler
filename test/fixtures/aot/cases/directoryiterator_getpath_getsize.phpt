--TEST--
AOT: DirectoryIterator getPath/getPathname/getSize (#33276)
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
    echo 'path:', $f->getPath(), "\n";
    echo 'pathname:', $f->getPathname(), "\n";
    echo 'size:', $f->getSize(), "\n";
}
--EXPECT--
name:a.txt
path:test/fixtures/aot/cases/directoryiterator_27289_fixture
pathname:test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt
size:2
