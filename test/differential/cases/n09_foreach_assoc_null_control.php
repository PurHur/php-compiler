<?php
// Control for #24261 — associative array with a null value must iterate all entries (Zend: 3).
// Localises packed-array hangs in n08 to the PACKED representation, not foreach/null in general.
$a = ['a' => 1, 'b' => null, 'c' => 3];
$c = 0;
foreach ($a as $v) {
    ++$c;
}
echo $c, "\n";
