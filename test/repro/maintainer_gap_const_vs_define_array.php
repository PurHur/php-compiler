<?php

declare(strict_types=1);

const ARR2 = [1, 2];
define('ARR', [1, 2]);
echo "const ";
var_export(ARR2);
echo "\ndefine ";
var_export(ARR);
echo "\n";
