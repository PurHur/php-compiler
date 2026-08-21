--TEST--
AOT: DirectoryIterator getPathname/getPath/__toString (#33274)
--FILE--
<?php
$dir = 'test/fixtures/aot/cases/directoryiterator_27289_fixture';
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    echo 'pn=', $f->getPathname(), "\n";
    echo 'p=', $f->getPath(), "\n";
    echo 's=', $f->__toString(), "\n";
}
--EXPECT--
pn=test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt
p=test/fixtures/aot/cases/directoryiterator_27289_fixture
s=test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt
