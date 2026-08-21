--TEST--
AOT: SplFileObject fgets/current/key/eof (#33319)
--FILE--
<?php
$p = 'test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt';
$o = new SplFileObject($p);
echo 'fgets=', var_export($o->fgets(), true), "\n";
echo 'key=', $o->key(), ' eof=', $o->eof() ? '1' : '0', "\n";
echo 'cur=', var_export($o->current(), true), "\n";
--EXPECT--
fgets='1
'
key=1 eof=0
cur='1
'
