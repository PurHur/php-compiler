--TEST--
stdlib strcmp()
--FILE--
<?php
echo strcmp('abc', 'abc'), "\n";
echo strcmp('abc', 'abd'), "\n";
echo strcmp('abd', 'abc'), "\n";
--EXPECT--
0
-1
1
