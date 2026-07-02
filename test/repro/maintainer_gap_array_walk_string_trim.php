<?php

declare(strict_types=1);

$arr = [1 => ' a '];
array_walk($arr, 'trim');
var_export($arr);
