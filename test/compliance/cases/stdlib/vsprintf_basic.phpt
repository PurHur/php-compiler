--TEST--
stdlib vsprintf() — format + args array (issue #3190)
--FILE--
<?php
echo vsprintf('%s-%d', ['a', 3]), "\n";
echo vsprintf('%%d=%d', [7]), "\n";
--EXPECT--
a-3
%d=7
