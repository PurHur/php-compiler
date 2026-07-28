--TEST--
stdlib FILTER_FLAG_GLOBAL_RANGE 0x10000000; FILTER_THROW_ON_FAILURE withheld on ≤8.4 (#24065)
--FILE--
<?php
echo FILTER_FLAG_GLOBAL_RANGE === 268435456 ? "global_ok\n" : ("global_bad=" . FILTER_FLAG_GLOBAL_RANGE . "\n");
echo defined('FILTER_THROW_ON_FAILURE') ? "throw_phantom\n" : "throw_absent\n";
$filter = get_defined_constants(true)['filter'] ?? [];
echo isset($filter['FILTER_FLAG_GLOBAL_RANGE']) && $filter['FILTER_FLAG_GLOBAL_RANGE'] === 268435456
    ? "bucket_global_ok\n"
    : "bucket_global_bad\n";
echo isset($filter['FILTER_THROW_ON_FAILURE']) ? "bucket_throw_phantom\n" : "bucket_throw_absent\n";
$priv = filter_var('10.0.0.1', FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE);
echo false === $priv ? "priv_rejected\n" : "priv_kept\n";
$pub = filter_var('8.8.8.8', FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE);
echo '8.8.8.8' === $pub ? "pub_ok\n" : "pub_bad\n";
--EXPECT--
global_ok
throw_absent
bucket_global_ok
bucket_throw_absent
priv_rejected
pub_ok
