--TEST--
stdlib preg_replace() null pattern — E_WARNING + null not TypeError (#11015)
--FILE--
<?php

$r = @preg_replace(null, 'x', 'abc');
echo 'null=', (int) ($r === null), "\n";
$r2 = @preg_replace('', 'x', 'abc');
echo 'empty=', (int) ($r2 === null), "\n";
--EXPECT--
null=1
empty=1
