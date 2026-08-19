--TEST--
Language: object vs string/int/bool === / !== matches zend_is_identical (#32523 leftover of #32515, Zend/zend_operators.c)
--FILE--
<?php
echo ((new stdClass()) === "a") ? "y\n" : "n\n";
echo ((new stdClass()) !== "a") ? "y\n" : "n\n";
echo ("a" === new stdClass()) ? "y\n" : "n\n";
echo ("a" !== new stdClass()) ? "y\n" : "n\n";
echo ((new stdClass()) === 1) ? "y\n" : "n\n";
echo ((new stdClass()) !== true) ? "y\n" : "n\n";
?>
--EXPECT--
n
y
n
y
n
y
