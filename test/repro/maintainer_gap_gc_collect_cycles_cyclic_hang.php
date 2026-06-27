<?php

declare(strict_types=1);

// Zend: self-referential array cycle — gc_collect_cycles() returns 0 immediately (#12608).
$a = [];
$a[0] = &$a;
$collected = gc_collect_cycles();
echo 'collected='.$collected."\n";
echo "ok\n";
