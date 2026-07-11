<?php
declare(strict_types=1);
$a = [];
$s = var_export($a[0] ?? null, true);
echo gettype($s), "\n", $s, "\n";
