<?php

declare(strict_types=1);

// #29767 — Zend 8.2 untyped range() $start: null coerces to 0 under strict_types.
error_reporting(E_ALL);
echo implode(',', range(null, 3)), "\n";
