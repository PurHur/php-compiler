--TEST--
AOT: SplFileInfo::openFile returns SplFileObject (#33305)
--FILE--
<?php
$f = new SplFileInfo('test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt');
$o = $f->openFile('r');
echo 'of_class=', get_class($o), "\n";
echo 'of_name=', $o->getFilename(), "\n";
echo 'of_path=', $o->getPath(), "\n";
echo 'of_pathname=', $o->getPathname(), "\n";
--EXPECT--
of_class=SplFileObject
of_name=a.txt
of_path=test/fixtures/aot/cases/directoryiterator_27289_fixture
of_pathname=test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt
