--TEST--
stdlib chroot() JIT — registered; invalid path returns false (#3500)
--JIT--
--FILE--
<?php
echo function_exists('chroot') ? "exists\n" : "missing\n";
$bad = @chroot('/no/such/phpc-chroot-path-'.getmypid());
echo $bad ? "bad_ok\n" : "bad_fail\n";
--EXPECT--
exists
bad_fail
