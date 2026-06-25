<?php

declare(strict_types=1);

$wm = new WeakMap();
$obj = new stdClass();
$wm[$obj] = 'val';
echo 'direct=', var_export($wm[$obj], true), "\n";
echo 'nullco=', var_export($wm[$obj] ?? null, true), "\n";
