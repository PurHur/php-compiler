<?php
/** Maintainer repro for #9479 — (int) on enum case must E_WARNING like Zend. */
enum E: int { case A = 1; }
$msgs = [];
set_error_handler(static function ($n, $m) use (&$msgs): bool { $msgs[] = $m; return true; });
var_export((int) E::A);
echo "\n";
var_export($msgs[0] ?? 'no warning');
