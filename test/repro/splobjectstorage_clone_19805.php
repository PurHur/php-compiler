<?php
$s = new SplObjectStorage();
$o = new stdClass;
$s[$o] = 1;
$t = clone $s;
echo $t->count(), "\n";
echo $t->offsetExists($o) ? "y" : "n", "\n";
