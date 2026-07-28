<?php
// Issue #24255 — SplFixedArray isset/offsetExists null slots (spl_fixedarray.c).
$a = new SplFixedArray(2);
$a[0] = null;
echo '0';
echo isset($a[0]) ? 'y' : 'n';
echo $a->offsetExists(0) ? 'y' : 'n';
echo ($a[0] ?? 'D');
echo "\n";
echo '1';
echo isset($a[1]) ? 'y' : 'n';
echo $a->offsetExists(1) ? 'y' : 'n';
echo ($a[1] ?? 'D');
echo "\n";
$a[1] = 0;
echo '1b';
echo isset($a[1]) ? 'y' : 'n';
echo $a->offsetExists(1) ? 'y' : 'n';
echo "\n";
$a[0] = 'x';
echo '0b';
echo isset($a[0]) ? 'y' : 'n';
echo $a->offsetExists(0) ? 'y' : 'n';
echo "\n";
