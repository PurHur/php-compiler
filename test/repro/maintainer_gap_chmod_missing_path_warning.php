<?php
// Issue #11408 — chmod() on missing path must emit E_WARNING.
error_reporting(E_ALL);
ini_set('display_errors', '1');
$ok = chmod('/nope/maintainer_gap_chmod_missing_path', 0644);
echo $ok ? "true\n" : "false\n";
