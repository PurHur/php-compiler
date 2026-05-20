--TEST--
stdlib exit() and die() stop execution (VM)
--FILE--
<?php
echo "before\n";
exit;
echo "never\n";
--EXPECT--
before
