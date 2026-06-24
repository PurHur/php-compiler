<?php
declare(strict_types=1);
$mixed = array_column([1, ['a' => 1], ['b' => 2]], null);
echo json_encode($mixed), "\n";
