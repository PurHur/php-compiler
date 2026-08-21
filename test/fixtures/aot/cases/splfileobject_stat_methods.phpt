--TEST--
AOT: SplFileObject inherited SplFileInfo stat methods (#33313)
--FILE--
<?php
$o = new SplFileObject('test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt');
echo 'file=', $o->isFile() ? '1' : '0', "\n";
echo 'size=', $o->getSize(), "\n";
echo 'ext=', $o->getExtension(), "\n";
echo 'type=', $o->getType(), "\n";
echo 'base=', $o->getBasename(), "\n";
--EXPECT--
file=1
size=2
ext=txt
type=file
base=a.txt
