<?php
// AOT: SplObjectStorage serialize bag (#33876) — serialize half.
$o = new stdClass();
$o->x = 1;
$s = new SplObjectStorage();
$s->attach($o, 42);
echo serialize($s), "\n";
