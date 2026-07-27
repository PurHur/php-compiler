<?php
// #24010: sort() under AOT — packed sort via __hashtable__sortPacked; implode coerces ints.
$a = [3, 1, 2];
sort($a);
echo implode(',', $a), "\n";
