<?php
/** Repro for #32556 — isset()/print on packed local array must AOT-compile. */
$a = [1];
var_dump(isset($a));
print $a;
echo "\n";
