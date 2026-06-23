<?php

declare(strict_types=1);

$a = [0 => 'x', 1 => 'y'];
krsort($a);
var_export($a);
echo "\n";

$b = [0 => 'p', 1 => 'q'];
ksort($b);
var_export($b);
echo "\n";
