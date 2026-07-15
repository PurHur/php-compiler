<?php
// #19025 — nl_langinfo(null) must coerce to 0, warn, return false (php-src Z_PARAM_LONG).

$r = @nl_langinfo(null);
echo 'result=', var_export($r, true), "\n";
