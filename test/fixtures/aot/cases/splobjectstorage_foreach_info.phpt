--TEST--
AOT: SplObjectStorage foreach yields objects; getInfo matches info (#28707)
--FILE--
<?php
$s = new SplObjectStorage();
$a = new stdClass();
$b = new stdClass();
$s[$a] = 10;
$s[$b] = 20;
echo 'direct=', $s[$a], "\n";
$s->rewind();
echo 'info=', $s->getInfo(), "\n";
echo 'cur=', ($s->current() === $a ? 'a' : 'other'), "\n";
foreach ($s as $k => $o) {
    echo $k, ':', $s[$o], ',';
}
echo "\n";
--EXPECT--
direct=10
info=10
cur=a
0:10,1:20,
