--TEST--
AOT: SplObjectStorage object-key offsetSet/offsetGet (#26787)
--FILE--
<?php
$s = new SplObjectStorage();
$o = new stdClass();
$s[$o] = 'x';
echo $s->count(), "\n";
echo $s[$o], "\n";
--EXPECT--
1
x
