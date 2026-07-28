<?php
/** #24156 usort-only — explicit compare (AOT <=> is a separate defect). */
$a = [3, 1, 2];
usort($a, fn($x, $y) => $x < $y ? -1 : ($x > $y ? 1 : 0));
echo implode(',', $a), "\n";
