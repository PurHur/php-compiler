<?php

declare(strict_types=1);

// Issue #9536 — %d overflow must saturate to PHP_INT_MAX/MIN, not TypeError.
var_export(sscanf('9223372036854775808', '%d'));
echo "\n";
var_export(sscanf('999999999999999999999', '%d'));
echo "\n";
var_export(sscanf('-9223372036854775809', '%d'));
echo "\n";
