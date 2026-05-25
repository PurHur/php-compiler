--TEST--
SplObjectStorage attach, bracket access, contains, count (JIT, issue #1998)
--FILE--
<?php
$storage = new SplObjectStorage();
$keyA = new stdClass();
$keyB = new stdClass();
$storage->attach($keyA, 10);
$storage[$keyB] = 20;
echo $storage->contains($keyA) ? '1' : '0';
echo $storage->contains($keyB) ? '1' : '0';
echo $storage[$keyA];
echo $storage[$keyB];
echo $storage->count();
echo "\n";
--EXPECT--
1110202
