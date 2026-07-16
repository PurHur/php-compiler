<?php
declare(strict_types=1);

echo function_exists('posix_initgroups') ? 'yes' : 'no', "\n";
enum E: string { case A = 'x'; }
try {
    posix_initgroups(E::A, 0);
    echo "enum-bad\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'username') ? "enum-ok\n" : "enum-msg\n";
}
$u = posix_getpwuid(posix_geteuid());
$r = @posix_initgroups($u['name'], $u['gid']);
echo is_bool($r) ? 'bool' : 'other', "\n";
