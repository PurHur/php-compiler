<?php

function f($a, $b = 2) { var_dump([$a, $b]); }
f(...['a' => 1]);
