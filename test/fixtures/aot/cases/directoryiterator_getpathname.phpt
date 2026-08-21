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
    if (!$f->isFile()) {
        continue;
    }
    echo 'name=', $f->getFilename(),
        ' path=', $f->getPath(),
        ' pathname=', $f->getPathname(),
        ' str=', $f->__toString(),
        "\n";
}
--EXPECT--
name=a.txt path=test/fixtures/aot/cases/directoryiterator_27289_fixture pathname=test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt str=a.txt
