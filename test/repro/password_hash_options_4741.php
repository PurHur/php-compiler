<?php
/**
 * Maintainer repro for #4741 — password_hash() options under JIT.
 */
$h = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 10]);
echo strlen($h) > 20 ? "ok\n" : "fail\n";
$info = password_get_info($h);
$cost = $info['options']['cost'];
var_export($cost);
echo "\n";
