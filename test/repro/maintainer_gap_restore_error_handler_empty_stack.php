<?php

declare(strict_types=1);

// Maintainer gap #12175 — restore_*_handler() on empty stack must return true (php-src-strict).
$r1 = restore_error_handler();
$r2 = restore_error_handler();
var_export([$r1, $r2]);
