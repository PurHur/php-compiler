<?php

function f($a, $b, $c) { var_dump([$a, $b, $c]); }
$args = [2, 3];

f(...$args, a: 1);

