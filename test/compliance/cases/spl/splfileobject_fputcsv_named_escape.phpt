--TEST--
SPL SplFileObject::fputcsv() named escape matches positional (#22097, ext/spl/spl_directory.c)
--FILE--
<?php
$o = new SplFileObject('php://memory', 'w+');
$o->fputcsv(['a', 'b'], separator: ',', enclosure: '"', escape: '\\');
$o->rewind();
$named = $o->fgets();

$o2 = new SplFileObject('php://memory', 'w+');
$o2->fputcsv(['a', 'b'], ',', '"', '\\');
$o2->rewind();
$positional = $o2->fgets();

echo $named === $positional && $named === "a,b\n" ? 'ok' : 'fail';
?>
--EXPECT--
ok
