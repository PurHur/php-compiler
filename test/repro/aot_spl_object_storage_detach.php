<?php
$s = new SplObjectStorage();
$a = new stdClass();
$b = new stdClass();
$s->attach($a, 'A');
$s->attach($b, 'B');
echo count($s), "\n";
echo $s[$a], "\n";
$s->detach($a);
echo count($s), "\n";
echo $s->contains($b) ? 'yes' : 'no', "\n";
unset($s[$b]);
echo count($s), "\n";
