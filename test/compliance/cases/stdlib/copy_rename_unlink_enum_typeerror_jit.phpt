--TEST--
stdlib copy/rename/unlink JIT — enum path operands TypeError (#6280)
--FILE--
<?php
enum PathEnum: string { case A = 'x'; }

$tests = [
    ['copy', PathEnum::A, '/tmp/y', 'copy(): Argument #1 ($from) must be of type string, PathEnum given'],
    ['copy', '/tmp/x', PathEnum::A, 'copy(): Argument #2 ($to) must be of type string, PathEnum given'],
    ['rename', PathEnum::A, '/tmp/y', 'rename(): Argument #1 ($from) must be of type string, PathEnum given'],
    ['rename', '/tmp/x', PathEnum::A, 'rename(): Argument #2 ($to) must be of type string, PathEnum given'],
    ['unlink', PathEnum::A, null, 'unlink(): Argument #1 ($filename) must be of type string, PathEnum given'],
];

foreach ($tests as [$fn, $a, $b, $expect]) {
    try {
        if (null === $b) {
            $fn($a);
        } else {
            $fn($a, $b);
        }
        echo "{$fn} uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
copy: copy(): Argument #1 ($from) must be of type string, PathEnum given
copy: copy(): Argument #2 ($to) must be of type string, PathEnum given
rename: rename(): Argument #1 ($from) must be of type string, PathEnum given
rename: rename(): Argument #2 ($to) must be of type string, PathEnum given
unlink: unlink(): Argument #1 ($filename) must be of type string, PathEnum given
