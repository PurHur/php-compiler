--TEST--
AOT: SplFileInfo::openFile returns SplFileObject (#33305)
--FILE--
<?php
$p = __DIR__ . '/directoryiterator_27289_fixture/a.txt';
$f = new SplFileInfo($p);
$o = $f->openFile('r');
echo get_class($o), ' ', $o->getFilename(), "\n";
--EXPECT--
SplFileObject a.txt
