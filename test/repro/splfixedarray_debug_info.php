<?php
// Repro #19783 — SplFixedArray var_dump must show indexed slots (php-src spl_fixedarray_object_debug_info).
$a = new SplFixedArray(2);
$a[0] = 1;
var_dump($a);
