--TEST--
posix_getpwuid()/getpwnam()/getgrgid()/getgrnam() via /etc/passwd (issue #6489)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('posix_getuid') ? 'getuid_yes' : 'getuid_no', "\n";
echo function_exists('posix_getpwuid') ? 'getpwuid_yes' : 'getpwuid_no', "\n";
echo function_exists('posix_getpwnam') ? 'getpwnam_yes' : 'getpwnam_no', "\n";
echo function_exists('posix_getgrgid') ? 'getgrgid_yes' : 'getgrgid_no', "\n";
echo function_exists('posix_getgrnam') ? 'getgrnam_yes' : 'getgrnam_no', "\n";

$pw = posix_getpwuid(posix_getuid());
var_export(is_array($pw) && isset($pw['name'], $pw['uid'], $pw['dir'], $pw['shell']));
echo "\n";
var_export($pw['uid'] === posix_getuid());
echo "\n";

$byName = posix_getpwnam($pw['name']);
var_export(is_array($byName) && $byName['name'] === $pw['name'] && $byName['uid'] === $pw['uid']);
echo "\n";

$gr = posix_getgrgid(posix_getgid());
var_export(is_array($gr) && isset($gr['name'], $gr['members'], $gr['gid']) && is_array($gr['members']));
echo "\n";
var_export($gr['gid'] === posix_getgid());
echo "\n";

$grByName = posix_getgrnam($gr['name']);
var_export(is_array($grByName) && $grByName['name'] === $gr['name'] && $grByName['gid'] === $gr['gid']);
echo "\n";

var_export(posix_getpwuid(999999));
echo "\n";
var_export(posix_getpwnam('__no_such_user_6489__'));
echo "\n";
--EXPECT--
getuid_yes
getpwuid_yes
getpwnam_yes
getgrgid_yes
getgrnam_yes
true
true
true
true
true
true
false
false
