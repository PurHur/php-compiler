--TEST--
stdlib token_name() JIT — native fallback map matches Zend names (#7254, #3171)
--FILE--
<?php
echo token_name(T_FUNCTION), "\n";
echo token_name(T_ECHO), "\n";
echo token_name(T_VARIABLE), "\n";
echo token_name(T_OPEN_TAG_WITH_ECHO), "\n";
echo token_name(T_PAAMAYIM_NEKUDOTAYIM), "\n";
?>
--EXPECT--
T_FUNCTION
T_ECHO
T_VARIABLE
T_OPEN_TAG_WITH_ECHO
T_DOUBLE_COLON
