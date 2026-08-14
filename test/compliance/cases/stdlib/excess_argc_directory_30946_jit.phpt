--TEST--
stdlib: Directory::{read,rewind,close} excess argc → ArgumentCountError JIT (#30946, ext/standard/dir.c)
--FILE--
<?php
$d = dir('.');
foreach (['read', 'rewind', 'close'] as $m) {
    try {
        $d->$m('x');
        echo $m, ': OK', "\n";
    } catch (Throwable $e) {
        echo $m, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$d2 = dir('.');
$first = $d2->read();
$d2->rewind();
$d2->close();
echo 'ok=', is_string($first) ? '1' : '0', "\n";
--EXPECT--
read: ArgumentCountError: Directory::read() expects exactly 0 arguments, 1 given
rewind: ArgumentCountError: Directory::rewind() expects exactly 0 arguments, 1 given
close: ArgumentCountError: Directory::close() expects exactly 0 arguments, 1 given
ok=1
