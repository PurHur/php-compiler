<?php

$src = ['a' => 1, 'b' => 2];
['a' => $v, ...$tail] = $src;
var_dump($v, $tail);
