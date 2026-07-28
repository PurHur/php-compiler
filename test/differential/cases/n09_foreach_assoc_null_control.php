<?php
// Control for #24261 — passes AOT. An associative array with a null value iterates correctly, which
// is what localises the hang in n08 to the PACKED array representation rather than to foreach or to
// null values in general.
$a = ['a' => 1, 'b' => null, 'c' => 3];
$c = 0;
foreach ($a as $v) {
    ++$c;
}
echo $c, "\n";
