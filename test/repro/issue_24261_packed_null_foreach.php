<?php
// Issue #24261 — foreach over packed array with null must terminate and count all elements.
$a = [1, null, 3];
$c = 0;
foreach ($a as $v) {
    ++$c;
}
echo $c, "\n";
