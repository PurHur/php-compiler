--TEST--
AOT: DirectoryIterator getExtension/getBasename/getType (#33280)
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
    echo 'ext:', $f->getExtension(), "\n";
    echo 'base:', $f->getBasename(), "\n";
    echo 'base_suf:', $f->getBasename('.txt'), "\n";
    echo 'type:', $f->getType(), "\n";
}
--EXPECT--
name:a.txt
ext:txt
base:a.txt
base_suf:a
type:file
