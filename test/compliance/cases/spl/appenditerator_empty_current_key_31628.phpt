--TEST--
Empty AppendIterator::current()/key() return null (#31628, ext/spl/spl_iterators.c)
--FILE--
<?php
$a = new AppendIterator();
echo 'valid=';
var_export($a->valid());
echo "\n";
try {
    $c = $a->current();
    echo 'current=';
    var_export($c);
    echo "\n";
} catch (Throwable $e) {
    echo 'current ', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $k = $a->key();
    echo 'key=';
    var_export($k);
    echo "\n";
} catch (Throwable $e) {
    echo 'key ', get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
valid=false
current=NULL
key=NULL
