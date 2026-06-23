--TEST--
stdlib nested substr(sprintf()) — inner string preserved (#10673, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

echo substr(sprintf('%o', 33188), -4), "\n";
echo var_export(substr(sprintf('%o', 33188), -4), true), "\n";
--EXPECT--
0644
'0644'
