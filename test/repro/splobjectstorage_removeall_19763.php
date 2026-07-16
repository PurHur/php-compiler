<?php
// Repro #19763 — SplObjectStorage removeAll/removeAllExcept
$a = new stdClass();
$b = new stdClass();
$o = new SplObjectStorage();
$o->attach($a);
$o->attach($b);
$o2 = new SplObjectStorage();
$o2->attach($a);
$o->removeAll($o2);
echo $o->count(), ',', var_export($o->contains($b), true), "\n";
$o->attach($a);
$keep = new SplObjectStorage();
$keep->attach($b);
$o->removeAllExcept($keep);
echo $o->count(), ',', var_export($o->contains($a), true), "\n";
