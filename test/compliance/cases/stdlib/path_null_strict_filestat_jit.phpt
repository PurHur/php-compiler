--TEST--
stdlib path/filestat builtins — null path TypeError under strict_types JIT (#15082, ext/standard/filestat.c)
--FILE--
<?php
declare(strict_types=1);
$checks = [
    ['unlink', [null], 'unlink(): Argument #1 ($filename) must be of type string, null given'],
    ['realpath', [null], 'realpath(): Argument #1 ($path) must be of type string, null given'],
    ['rename', [null, '/tmp/x'], 'rename(): Argument #1 ($from) must be of type string, null given'],
    ['chmod', [null, 0777], 'chmod(): Argument #1 ($filename) must be of type string, null given'],
    ['filesize', [null], 'filesize(): Argument #1 ($filename) must be of type string, null given'],
    ['filemtime', [null], 'filemtime(): Argument #1 ($filename) must be of type string, null given'],
    ['pathinfo', [null], 'pathinfo(): Argument #1 ($path) must be of type string, null given'],
    ['dirname', [null], 'dirname(): Argument #1 ($path) must be of type string, null given'],
    ['basename', [null], 'basename(): Argument #1 ($path) must be of type string, null given'],
    ['is_file', [null], 'is_file(): Argument #1 ($filename) must be of type string, null given'],
    ['file_exists', [null], 'file_exists(): Argument #1 ($filename) must be of type string, null given'],
];
foreach ($checks as [$fn, $args, $expected]) {
    try {
        $fn(...$args);
        echo "fail: {$fn}(null)\n";
        exit(1);
    } catch (TypeError $e) {
        if ($expected !== $e->getMessage()) {
            echo 'fail: ', $fn, '(): ', $e->getMessage(), "\n";
            exit(1);
        }
    }
}
echo "ok\n";
--EXPECT--
ok
