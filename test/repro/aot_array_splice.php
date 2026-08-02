<?php
$a = [1, 2, 3, 4];
array_splice($a, 1, 2, [9]);
echo json_encode($a), PHP_EOL;
