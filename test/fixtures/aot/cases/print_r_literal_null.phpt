--TEST--
AOT: print_r(null) matches Zend — empty output, no segfault (#24220)
--FILE--
<?php
print_r(null);
echo "|done\n";
--EXPECT--
|done
