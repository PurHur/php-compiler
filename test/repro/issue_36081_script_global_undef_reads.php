<?php
// #36081 — {main} script-global CV reads must ZEND_CHECK_UNDEFINED_VAR (echo/ARG_SEND/assign RHS).
echo $y, "\n";
var_dump($y);
$x = $y;
var_dump($x);
