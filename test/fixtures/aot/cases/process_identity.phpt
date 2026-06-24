--TEST--
AOT process identity builtins (issue #6119)
--FILE--
<?php
$uid = getmyuid();
$gid = getmygid();
echo $uid >= 0 ? "uid\n" : "bad_uid\n";
echo $gid >= 0 ? "gid\n" : "bad_gid\n";
$user = get_current_user();
echo is_string($user) && '' !== $user ? "user\n" : "bad_user\n";
$ml = get_cfg_var('memory_limit');
echo $ml === '-1' ? "cfg_ml\n" : "bad_cfg\n";
--EXPECT--
uid
gid
user
cfg_ml
