<?php

/**
 * #31450 — readdir() optional $dir_handle / EG(default_directory).
 * php-src: ext/standard/dir.c / dir.stub.php
 */
error_reporting(E_ALL);

$rf = new ReflectionFunction('readdir');
echo 'return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '-', "\n";
$p = $rf->getParameters()[0];
echo 'default=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-', "\n";

try {
    readdir();
    echo "fail: bare without open\n";
    exit(1);
} catch (TypeError $e) {
    if ('No resource supplied' !== $e->getMessage()) {
        echo 'fail: bare noopen msg: ', $e->getMessage(), "\n";
        exit(1);
    }
}

$dir = sys_get_temp_dir().'/phpc_readdir_opt_'.getmypid();
mkdir($dir);
touch($dir.'/a.txt');
$dh = opendir($dir);
if (false === $dh) {
    echo "fail: opendir\n";
    exit(1);
}

$seen = [];
try {
    while (false !== ($e = readdir())) {
        $seen[] = $e;
    }
} catch (Throwable $e) {
    echo 'fail: bare loop: ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}
sort($seen);
if (!in_array('.', $seen, true) || !in_array('..', $seen, true) || !in_array('a.txt', $seen, true)) {
    echo 'fail: entries=', var_export($seen, true), "\n";
    exit(1);
}
echo "bare_ok\n";

rewinddir($dh);
$first = readdir(null);
if (!is_string($first)) {
    echo 'fail: null arg: ', var_export($first, true), "\n";
    exit(1);
}
echo "null_ok\n";

try {
    readdir($dh, 1);
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
