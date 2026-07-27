--TEST--
get_defined_constants(true) omits empty user bucket — Zend match (#23732, Zend/zend_builtin_functions.c)
--FILE--
<?php
$c = get_defined_constants(true);
echo array_key_exists('user', $c) ? "empty_user=fail\n" : "empty_user=ok\n";
define('ISSUE_23732_U', 1);
$c2 = get_defined_constants(true);
echo isset($c2['user']['ISSUE_23732_U']) ? "defined_user=ok\n" : "defined_user=fail\n";
--EXPECT--
empty_user=ok
defined_user=ok
