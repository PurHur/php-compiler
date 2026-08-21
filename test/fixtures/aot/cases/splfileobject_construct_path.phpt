--TEST--
AOT: SplFileObject::__construct initialises SplFileInfo path props (#33308)
--FILE--
<?php
$o = new SplFileObject('test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt');
echo 'class=', get_class($o), "\n";
echo 'name=', $o->getFilename(), "\n";
echo 'path=', $o->getPath(), "\n";
echo 'pn=', $o->getPathname(), "\n";
--EXPECT--
class=SplFileObject
name=a.txt
path=test/fixtures/aot/cases/directoryiterator_27289_fixture
pn=test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt
