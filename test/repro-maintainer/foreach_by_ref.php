<?php
declare(strict_types=1);

$a = [1, 2, 3];
foreach ($a as &$v) {
    $v *= 10;
}
echo json_encode($a), "\n";
