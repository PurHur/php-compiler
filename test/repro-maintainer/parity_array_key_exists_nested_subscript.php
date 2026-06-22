<?php

declare(strict_types=1);

$bt = [['file' => 'x', 'line' => 1]];
var_dump(array_key_exists('file', $bt[0]));
