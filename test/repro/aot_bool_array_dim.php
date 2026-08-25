<?php
/** Repro for #34667 — bool array dim AOT (zend_fetch_dimension coerce true→1). */
$a = ['1' => 7];
echo $a[true], "\n";
$k = true;
var_dump(isset($a[$k]));
