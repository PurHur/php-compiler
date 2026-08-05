<?php
// #27545 — thin AOT array_values on a *variable* assoc array (re-#27212).
$a = ['a' => 1, 'b' => 2];
echo implode(',', array_values($a)), "\n";
