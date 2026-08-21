--TEST--
AOT DirectoryIterator getExtension/getBasename/getType (#33280)
--FILE--
<?php
$dir = 'test/fixtures/aot/cases/directoryiterator_27289_fixture';
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    echo 'ext=', $f->getExtension(), "\n";
    echo 'bn=', $f->getBasename(), "\n";
    echo 'bn2=', $f->getBasename('.txt'), "\n";
    echo 'ty=', $f->getType(), "\n";
}
--EXPECT--
ext=txt
bn=a.txt
bn2=a
ty=file
