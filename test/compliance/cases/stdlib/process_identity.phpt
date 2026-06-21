--TEST--
stdlib process identity builtins (issue #6119)
--FILE--
<?php
foreach (['getmyuid', 'getmygid', 'get_current_user', 'get_cfg_var'] as $f) {
    echo $f, ': ', function_exists($f) ? 'yes' : 'NO', "\n";
}
$uid = getmyuid();
$gid = getmygid();
echo $uid >= 0 ? "uid\n" : "bad_uid\n";
echo $gid >= 0 ? "gid\n" : "bad_gid\n";
echo getmyuid() === $uid ? "uid_stable\n" : "uid_bad\n";
echo getmygid() === $gid ? "gid_stable\n" : "gid_bad\n";
$user = get_current_user();
echo is_string($user) && '' !== $user ? "user\n" : "bad_user\n";
echo $user !== 'Unknown' ? "user_named\n" : "unknown_bad\n";
$ml = get_cfg_var('memory_limit');
echo is_string($ml) && '' !== $ml ? "cfg_ml\n" : "bad_cfg\n";
echo get_cfg_var('unknown_cfg_key') === false ? "cfg_false\n" : "cfg_bad\n";
--EXPECT--
getmyuid: yes
getmygid: yes
get_current_user: yes
get_cfg_var: yes
uid
gid
uid_stable
gid_stable
user
user_named
cfg_ml
cfg_false
