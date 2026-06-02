--TEST--
AOT: eval() parse error returns false for compile-time literal (issue #4652)
--FILE--
<?php
$bad = eval('syntax error;');
echo $bad === false ? "false\n" : "not-false\n";
--EXPECT--
false
