--TEST--
SplObjectStorage JIT instance methods (#1998)
--JIT--
--FILE--
<?php
$storage = new SplObjectStorage();
$a = new stdClass();
$b = new stdClass();
$storage->attach($a, 10);
$storage->attach($b);
echo $storage->contains($a) ? '1' : '0', "\n";
echo $storage->contains($b) ? '1' : '0', "\n";
echo $storage->offsetExists($a) ? '1' : '0', "\n";
echo $storage->offsetGet($a), "\n";
echo $storage->count(), "\n";
$storage->offsetSet($b, 20);
echo $storage->offsetGet($b), "\n";
$c = new stdClass();
$storage[$c] = 30;
echo $storage->offsetGet($c), "\n";
echo isset($storage[$a]) ? '1' : '0', "\n";
--EXPECT--
1
1
1
10
2
20
30
1
