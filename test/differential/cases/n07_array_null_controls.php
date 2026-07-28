<?php
// Controls for #24232 — both pass AOT, and they are what makes n06 attributable.
//
// A scalar null compares correctly, and isset() on an existing-but-null array element returns false
// exactly like Zend. So the defect is in reading the element back, not in null itself and not in the
// array's key bookkeeping.
$z = null;
$a = [1, null, 3];
echo ($z === null) ? 'n' : 'x';
echo isset($a[1]) ? 'set' : 'notset';
echo count($a);
echo "\n";
