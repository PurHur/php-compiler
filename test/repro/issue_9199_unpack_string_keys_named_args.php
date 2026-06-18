<?php

function f($a, $b = 2) { var_dump([$a, $b]); }

$args = ['a' => 1];
f(...$args);

