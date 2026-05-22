--TEST--
stdlib strcasecmp()
--FILE--
<?php
echo strcasecmp('abc', 'ABC'), "\n";
echo strcasecmp('abc', 'abd'), "\n";
echo strcasecmp('ABD', 'abc'), "\n";
--EXPECT--
0
-1
1
