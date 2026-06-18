--TEST--
Language: hoisted zero-valued ConstFetch binds to first builtin arg (#9548, zend_execute.c)
--FILE--
<?php
echo cal_to_jd(CAL_GREGORIAN, 6, 6, 2026), "\n";
?>
--EXPECT--
2461198
