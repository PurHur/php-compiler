<?php

declare(strict_types=1);

/** Bootstrap AOT: getenv() in selfhost bundle (M3 dispatch prerequisite). */

$key = 'PHP_COMPILER_M3_SOURCE';
$val = getenv($key);
echo is_string($val) || false === $val ? '1' : '0';
