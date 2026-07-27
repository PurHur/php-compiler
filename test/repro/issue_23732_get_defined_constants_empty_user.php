<?php
// Issue #23732 — get_defined_constants(true) omits empty user bucket
$c = get_defined_constants(true);
echo array_key_exists('user', $c) ? "empty_user=fail\n" : "empty_user=ok\n";
define('U', 1);
$c2 = get_defined_constants(true);
echo isset($c2['user']['U']) ? "defined_user=ok\n" : "defined_user=fail\n";
