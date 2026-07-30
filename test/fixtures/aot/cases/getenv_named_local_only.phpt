--TEST--
AOT: getenv named local_only without name returns env array (#24855)
--FILE--
<?php
putenv('PHPC_TEST_GE_NAMED=1');
$v = getenv(local_only: true);
echo is_array($v) ? "named_local_only=array\n" : "named_local_only=not-array\n";
echo (is_array($v) && isset($v['PHPC_TEST_GE_NAMED'])) ? "named_has=1\n" : "named_has=0\n";
echo 'putenv_hit=', getenv('PHPC_TEST_GE_NAMED'), "\n";
$all = getenv();
echo (is_array($all) && count($all) === count($v)) ? "match_counts=1\n" : "match_counts=0\n";
--EXPECT--
named_local_only=array
named_has=1
putenv_hit=1
match_counts=1
