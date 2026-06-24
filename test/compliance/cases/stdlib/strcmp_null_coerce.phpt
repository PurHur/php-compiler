--TEST--
stdlib strcmp()/strncmp() null operands coerce without strict_types (#11261, ext/standard/string.c)
--FILE--
<?php
echo strcmp(null, 'a'), "\n";
echo strncmp(null, 'a', 1), "\n";
echo strcmp('a', null), "\n";
--EXPECT--
-1
-1
1
