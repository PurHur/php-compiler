<?php
declare(strict_types=1);
// Issue #11700 — ob_flush() on nested buffer must flush to parent, not stdout.

ob_start();
echo 'a';
ob_start();
echo 'b';
ob_flush();
$inner = ob_get_clean();
$outer = ob_get_clean();
echo 'outer=' . var_export($outer, true) . "\n";
echo 'inner=' . var_export($inner, true) . "\n";
