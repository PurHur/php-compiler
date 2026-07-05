--TEST--
Language: inline array literal dim-fetch with @ (#16462, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);
var_dump(@['a' => 1]['a']);
?>
--EXPECT--
int(1)
