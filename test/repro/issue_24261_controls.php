<?php
// Control: foreach without null
$a = [1, 2, 3];
$c = 0;
foreach ($a as $v) {
    ++$c;
}
echo "packed_ok:", $c, "\n";

// Control: read null element
$a2 = [1, null, 3];
echo "read:", var_export($a2[1], true), "\n";
