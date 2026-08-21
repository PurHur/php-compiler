--TEST--
AOT: SplFileObject inherited SplFileInfo isFile/getSize/getExtension/getType (#33313)
--FILE--
<?php
$o = new SplFileObject('test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt');
echo 'isFile=', $o->isFile() ? '1' : '0', "\n";
echo 'size=', $o->getSize(), "\n";
echo 'ext=', $o->getExtension(), "\n";
echo 'type=', $o->getType(), "\n";
?>
--EXPECT--
isFile=1
size=2
ext=txt
type=file
