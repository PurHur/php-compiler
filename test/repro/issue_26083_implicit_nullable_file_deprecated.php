<?php
/**
 * Repro #26083 — file-level implicit nullable emits E_DEPRECATED under PROFILE=8.4
 * (Zend 8.4 default error_reporting includes E_DEPRECATED; guest must match).
 */
error_reporting(E_ALL);
function f_file(string $s = null) { return $s; }
echo "file_done\n";
