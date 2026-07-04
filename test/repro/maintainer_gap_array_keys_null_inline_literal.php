<?php

declare(strict_types=1);

$a = [null => 1];
echo 'variable ', var_export(array_keys($a, null), true), "\n";
echo 'inline ', var_export(array_keys([null => 1], null), true), "\n";
