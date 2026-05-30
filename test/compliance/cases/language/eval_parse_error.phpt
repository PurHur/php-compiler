--TEST--
language eval() parse error returns false (VM, issue #3358)
--FILE--
<?php
$bad = eval('syntax error;');
echo $bad === false ? "false\n" : "not-false\n";
--EXPECT--
false
