<?php
declare(strict_types=1);

$r = array_map(fn($x) => $x, [new stdClass()]);
var_export($r);
echo "\n";
