--TEST--
AOT: Spoofchecker::* ClassConstFetch seeds (#35396 peer #35389)
--FILE--
<?php
echo 'SINGLE_SCRIPT=', Spoofchecker::SINGLE_SCRIPT, "\n";
echo 'INVISIBLE=', Spoofchecker::INVISIBLE, "\n";
echo 'ALL_CHECKS=', Spoofchecker::ALL_CHECKS, "\n";
--EXPECT--
SINGLE_SCRIPT=16
INVISIBLE=32
ALL_CHECKS=65535
