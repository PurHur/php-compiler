<?php
// Issue #10028 — ini_get()/ini_set() option:/value: named parameters
$before = ini_get(option: 'display_errors');
ini_set(option: 'display_errors', value: '0');
$mid = ini_get(option: 'display_errors');
ini_set(option: 'display_errors', value: $before);
$after = ini_get(option: 'display_errors');
echo ($mid === '0' && $after === $before) ? "ok\n" : "fail\n";
