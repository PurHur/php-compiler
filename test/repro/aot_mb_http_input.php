<?php

/**
 * AOT: mb_http_input() getter + type letters match Zend (#35271 leftover of #4636).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_http_input)
 */
var_dump(mb_http_input());
var_dump(mb_http_input('G'));
var_dump(mb_http_input('I'));
var_dump(mb_http_input('L'));
$t = 'I';
var_dump(mb_http_input($t));
$u = 'L';
var_dump(mb_http_input($u));
