<?php

/**
 * #31451 / #28308 — rewinddir() optional $dir_handle + Reflection void.
 * php-src: ext/standard/dir.c / dir.stub.php
 */
error_reporting(E_ALL);

$rf = new ReflectionFunction('rewinddir');
echo 'return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '-', "\n";
$p = $rf->getParameters()[0];
echo 'default=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-', "\n";

try {
    rewinddir();
    echo "fail: bare without open\n";
    exit(1);
} catch (TypeError $e) {
    if ('No resource supplied' !== $e->getMessage()) {
        echo 'fail: bare noopen msg: ', $e->getMessage(), "\n";
        exit(1);
    }
}

$dir = sys_get_temp_dir().'/phpc_rewinddir_opt_'.getmypid();
mkdir($dir);
touch($dir.'/a.txt');
$dh = opendir($dir);
if (false === $dh) {
    echo "fail: opendir\n";
    exit(1);
}

$first = readdir($dh);
if (!is_string($first)) {
    echo "fail: first readdir\n";
    exit(1);
}

try {
    rewinddir();
    echo "bare_ok\n";
} catch (Throwable $e) {
    echo 'fail: bare: ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

$again = readdir($dh);
if ($again !== $first) {
    echo 'fail: after bare rewind expected ', var_export($first, true), ' got ', var_export($again, true), "\n";
    exit(1);
}

readdir($dh);
try {
    rewinddir(null);
    echo "null_ok\n";
} catch (Throwable $e) {
    echo 'fail: null: ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}
$afterNull = readdir($dh);
if ($afterNull !== $first) {
    echo 'fail: after null rewind expected ', var_export($first, true), ' got ', var_export($afterNull, true), "\n";
    exit(1);
}

try {
    rewinddir($dh, 1);
    echo "fail: expected ArgumentCountError\n";
    exit(1);
} catch (ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), 'at most 1 argument')) {
        echo 'fail: excess msg: ', $e->getMessage(), "\n";
        exit(1);
    }
}

closedir($dh);
unlink($dir.'/a.txt');
rmdir($dir);
echo "ok\n";
