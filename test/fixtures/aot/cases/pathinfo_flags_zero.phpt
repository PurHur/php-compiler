--TEST--
AOT: pathinfo() flags 0 → empty string (#24941)
--FILE--
<?php
$z = pathinfo('/a/b.txt', 0);
echo 'type=', gettype($z), "\n";
echo 'len=', strlen($z), "\n";
echo 'eq=', ($z === '') ? 'yes' : 'no', "\n";
--EXPECT--
type=string
len=0
eq=yes
