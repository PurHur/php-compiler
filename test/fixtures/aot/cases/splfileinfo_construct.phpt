--TEST--
AOT: SplFileInfo::__construct initialises path/filename (#33290)
--FILE--
<?php
$f = new SplFileInfo('test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt');
echo 'path=', $f->getPath(), "\n";
echo 'name=', $f->getFilename(), "\n";
echo 'pn=', $f->getPathname(), "\n";
--EXPECT--
path=test/fixtures/aot/cases/directoryiterator_27289_fixture
name=a.txt
pn=test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt
