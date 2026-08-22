--TEST--
AOT: SplObjectStorage::detach / offsetUnset remove object keys (#33841)
--FILE--
<?php
$s = new SplObjectStorage();
$a = new stdClass();
$b = new stdClass();
$s->attach($a, 'A');
$s->attach($b, 'B');
echo $s->count(), "\n";
echo $s[$a], "\n";
$s->detach($a);
echo $s->count(), "\n";
echo $s->contains($b) ? 'yes' : 'no', "\n";
unset($s[$b]);
echo $s->count(), "\n";
--EXPECT--
2
A
1
yes
0
