--TEST--
AOT: filetype() missing path — E_WARNING + false (#10548, #10581, ext/standard/filestat.c)
--FILE--
<?php
$r = filetype('/no/such/phpc-filetype-missing-path-aot');
echo ($r === false ? 'false' : 'bad'), "\n";
--EXPECT--
false
