<?php
// #24010: nested foreach — Iterator\Value array writes are dynamic (not fixed packed size);
// foreach value copy must preserve nested hashtables.
$g = [[1, 2], [3, 4]];
$t = 0;
foreach ($g as $row) { foreach ($row as $c) { $t += $c; } }
echo $t, "\n";
