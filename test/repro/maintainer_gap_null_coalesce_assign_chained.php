<?php
declare(strict_types=1);

$x = null;
$y = null;
var_dump($x ??= $y ??= 1, $x, $y);
