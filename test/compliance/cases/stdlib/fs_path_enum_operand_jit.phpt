--TEST--
stdlib filesystem path builtins JIT — enum path operands TypeError (#5735)
--FILE--
<?php
enum PathEnum: string { case A = 'x'; }

$tests = [
    'file_get_contents',
    'file_exists',
    'is_file',
    'unlink',
];

foreach ($tests as $fn) {
    try {
        $fn(PathEnum::A);
        echo "{$fn} uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
file_get_contents: file_get_contents(): Argument #1 ($filename) must be of type string, PathEnum given
file_exists: file_exists(): Argument #1 ($filename) must be of type string, PathEnum given
is_file: is_file(): Argument #1 ($filename) must be of type string, PathEnum given
unlink: unlink(): Argument #1 ($filename) must be of type string, PathEnum given
