<?php
// Issue #27207 — AOT filter_var(FILTER_VALIDATE_IP) must match Zend/VM for
// compile-time literals and dynamic (argv) strings. NestedJIT ?string was corrupt;
// bridge uses isValidInt + input __string__* (peer EMAIL #27068 / URL #27206).
$litOk = filter_var('127.0.0.1', FILTER_VALIDATE_IP);
$litBad = filter_var('999.0.0.1', FILTER_VALIDATE_IP);
$dyn = $argv[1] ?? ('127.0.' . '0.1');
$dynOk = filter_var($dyn, FILTER_VALIDATE_IP);
var_export($litOk);
echo "\n";
var_export($litBad);
echo "\n";
var_export($dynOk);
echo "\n";
