--TEST--
stdlib trigger_error() JIT user warning and notice (issue #1221)
--FILE--
<?php
trigger_error('jit-warn', E_USER_WARNING);
echo "w\n";
trigger_error('jit-note', E_USER_NOTICE);
echo "n\n";
--EXPECT--
w
n
