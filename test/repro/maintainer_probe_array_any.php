<?php
$arr = [1, 2, 3];
echo array_any($arr, fn($v) => $v > 2) ? 'true' : 'false';
echo "\n";
