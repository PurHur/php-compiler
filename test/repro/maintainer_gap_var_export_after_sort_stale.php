<?php

declare(strict_types=1);

/**
 * Repro #12722 — var_export() after sort-family by-ref mutations must show live order.
 */

$a = [3, 1, 2];
sort($a);
var_export($a);
echo "\n";
