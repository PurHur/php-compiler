<?php
declare(strict_types=1);
echo 'gid_exists=', (int) function_exists('posix_getgid'), "\n";
echo 'egid_exists=', (int) function_exists('posix_getegid'), "\n";
echo 'groups_exists=', (int) function_exists('posix_getgroups'), "\n";
$gid = posix_getgid();
$egid = posix_getegid();
$groups = posix_getgroups();
echo 'gid=', $gid, "\n";
echo 'egid=', $egid, "\n";
echo 'groups_count=', count($groups), "\n";
echo $gid >= 0 && $egid >= 0 && count($groups) >= 0 ? "all ok\n" : "fail\n";
