<?php

declare(strict_types=1);

var_export(posix_geteuid());
echo "\n";
var_export(posix_getegid());
echo "\n";
$groups = posix_getgroups();
var_export(is_array($groups));
echo "\n";
$uname = posix_uname();
var_export(is_array($uname) && isset($uname['nodename']));
echo "\n";
