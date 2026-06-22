<?php

$c = 0;
var_export(str_ireplace(['a', 'b'], 'X', 'AbBa', $c));
echo ' count=', $c, "\n";
