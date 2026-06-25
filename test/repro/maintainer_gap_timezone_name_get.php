<?php
// Issue #11746 — timezone_name_get() procedural wrapper.
echo function_exists('timezone_name_get') ? "true\n" : "false\n";
$tz = timezone_open('UTC');
echo timezone_name_get($tz), "\n";
