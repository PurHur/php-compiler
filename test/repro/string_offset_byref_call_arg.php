<?php
error_reporting(E_ALL);
function f(&$x){ $x = "Z"; }
$s = "ab";
try { f($s[0]); echo "str_ok s=$s\n"; }
catch (Throwable $e) { echo get_class($e), ":", $e->getMessage(), "\n"; }
