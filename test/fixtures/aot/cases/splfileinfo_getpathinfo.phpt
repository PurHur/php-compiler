--TEST--
AOT: SplFileInfo getFileInfo/getPathInfo (#33300)
--FILE--
<?php
$p = __DIR__ . '/directoryiterator_27289_fixture/a.txt';
$f = new SplFileInfo($p);
$fi = $f->getFileInfo();
echo get_class($fi), ' ', $fi->getFilename(), "\n";
$pi = $f->getPathInfo();
echo get_class($pi), ' ', $pi->getFilename(), "\n";
--EXPECT--
SplFileInfo a.txt
SplFileInfo directoryiterator_27289_fixture
