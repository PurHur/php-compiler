<?php
const C = array_find([1, 2, 3], fn($v) => $v > 1);
var_export(C);
echo "\n";
