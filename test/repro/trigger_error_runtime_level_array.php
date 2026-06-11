<?php

declare(strict_types=1);

$arr = [1024];
$lvl = $arr[0];
trigger_error('hello', $lvl);
echo "ok\n";
