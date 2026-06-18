<?php

declare(strict_types=1);

$a = ['b', 'a10', 'a2'];
natsort($a);
var_export($a);
echo "\n";

$b = ['b', 'A', 'c'];
natcasesort($b);
var_export($b);
echo "\n";
