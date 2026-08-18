<?php
/**
 * #32281 — float ++/-- must stay float (zend increment_function IS_DOUBLE).
 * AOT value-box inc/dec used __value__readLong and truncated 1.5 → int(2).
 */
$x = 1.5;
$old = $x++;
var_dump($old, $x);
$y = 1.5;
++$y;
var_dump($y);
$z = 2.5;
$z--;
var_dump($z);
