--TEST--
stdlib process identity builtins JIT (issue #6119)
--FILE--
<?php
$uid = getmyuid();
$gid = getmygid();
echo $uid >= 0 ? "uid\n" : "bad_uid\n";
echo $gid >= 0 ? "gid\n" : "bad_gid\n";
echo getmyuid() === $uid ? "uid_stable\n" : "uid_bad\n";
echo getmygid() === $gid ? "gid_stable\n" : "gid_bad\n";
$user = get_current_user();
echo is_string($user) && '' === $user ? "empty_user\n" : (is_string($user) && '' !== $user ? "user\n" : "bad_user\n");
echo $user !== 'Unknown' ? "user_named\n" : "unknown_bad\n";
$ml = get_cfg_var('memory_limit');
echo is_string($ml) && '' !== $ml ? "cfg_ml\n" : "bad_cfg\n";
--EXPECT--
uid
gid
uid_stable
gid_stable
empty_user
user_named
cfg_ml
