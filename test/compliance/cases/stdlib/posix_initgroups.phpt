--TEST--
posix_initgroups() registered; enum args TypeError; root call bool (#19476)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('posix_initgroups') ? 'yes' : 'no', "\n";

enum E: string { case A = 'x'; }
try {
    posix_initgroups(E::A, 0);
    echo "enum-user-bad\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'username') ? "enum-user-ok\n" : "enum-user-msg\n";
}
try {
    posix_initgroups('root', E::A);
    echo "enum-gid-bad\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'group_id') ? "enum-gid-ok\n" : "enum-gid-msg\n";
}

$u = posix_getpwuid(posix_geteuid());
if (false === $u) {
    echo "pw-bad\n";
    exit(0);
}
$r = @posix_initgroups($u['name'], $u['gid']);
echo is_bool($r) ? "call-ok\n" : "call-bad\n";
if (!$r) {
    echo 'err=', posix_get_last_error(), "\n";
} else {
    echo "err=0\n";
}
--EXPECTF--
yes
enum-user-ok
enum-gid-ok
call-ok
err=%d
