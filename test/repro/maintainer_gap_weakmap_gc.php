<?php

declare(strict_types=1);

$k = new stdClass();
$wm = new WeakMap();
$wm[$k] = 42;
$k = null;
var_export(count($wm));
