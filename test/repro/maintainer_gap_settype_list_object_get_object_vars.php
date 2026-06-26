<?php

declare(strict_types=1);

$list = [1, 2, 3];
settype($list, 'object');
$vars = get_object_vars($list);
if ([0 => 1, 1 => 2, 2 => 3] !== $vars) {
    echo 'fail get_object_vars: ', var_export($vars, true), "\n";
    exit(1);
}
echo "ok\n";
