<?php
// @differential-repeat: 10 memory-safety soak — array COW + string growth (#36397 / AGENTS.md heap class)
/**
 * Deterministic refcount churn: shared array separate-on-write, then string concat growth.
 * Must match Zend on every repeat (intermittent heap bugs fail some of N runs).
 */
$a = [1, 2, 3];
$b = $a;
$b[] = 4;
$t = 0;
foreach ($b as $v) {
    $t += $v;
}
$s = 'x';
for ($i = 0; $i < 64; $i++) {
    $s = $s . 'y';
}
echo $t, '|', count($a), '|', count($b), '|', strlen($s), "\n";
