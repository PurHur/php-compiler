<?php
// AOT: SplObjectStorage addAll / removeAll / removeAllExcept (#33847)
$a = new stdClass();
$b = new stdClass();
$c = new stdClass();

$s = new SplObjectStorage();
$s->attach($a, 'A');
$s->attach($b, 'B');
$s->attach($c, 'C');
$rm = new SplObjectStorage();
$rm->attach($a);
$rm->attach($c);
$s->removeAll($rm);
echo $s->count(), "\n";
echo $s->contains($b) ? 'yes' : 'no', "\n";
echo $s[$b], "\n";

$dest = new SplObjectStorage();
$src = new SplObjectStorage();
$o1 = new stdClass();
$o2 = new stdClass();
$src->attach($o1, 'info1');
$src->attach($o2, 'info2');
$dest->addAll($src);
echo $dest->count(), "\n";
echo $dest[$o1], "\n";
echo $dest[$o2], "\n";

$keep = new SplObjectStorage();
$keep->attach($o2);
$dest->removeAllExcept($keep);
echo $dest->count(), "\n";
echo $dest->contains($o1) ? 'yes' : 'no', "\n";
echo $dest->contains($o2) ? 'yes' : 'no', "\n";
