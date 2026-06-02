<?php

declare(strict_types=1);

/** Issue #3687 — null key coerces to empty string (php-src ext/standard/array.c). */
$a = ['' => 1];
var_dump(array_key_exists(null, $a));
