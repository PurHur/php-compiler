--TEST--
SplObjectStorage detach/contains/foreach (#13181)
--FILE--
<?php
$storage = new SplObjectStorage();
$a = new stdClass();
$b = new stdClass();
$storage->attach($a);
$storage->attach($b);
echo $storage->contains($a) ? '1' : '0', "\n";
echo $storage->contains($b) ? '1' : '0', "\n";
echo $storage->count(), "\n";
$storage->detach($a);
echo $storage->contains($a) ? '1' : '0', "\n";
echo $storage->contains($b) ? '1' : '0', "\n";
echo $storage->count(), "\n";
$seen = 0;
foreach ($storage as $obj) {
    if ($obj === $b) {
        ++$seen;
    }
}
echo $seen, "\n";
--EXPECT--
1
1
2
0
1
1
1
