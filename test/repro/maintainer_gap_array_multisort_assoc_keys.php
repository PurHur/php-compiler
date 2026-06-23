<?php

declare(strict_types=1);

$a = ['a' => 1, 'm' => 2, 'z' => 3];
array_multisort($a);
echo var_export($a, true), "\n";
if ($a !== ['a' => 1, 'm' => 2, 'z' => 3]) {
    exit(1);
}
