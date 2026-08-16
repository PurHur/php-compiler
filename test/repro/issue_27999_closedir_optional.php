<?php

/**
 * #27999 — closedir() optional $dir_handle / EG(default_directory) + Reflection void.
 * php-src: ext/standard/dir.c / dir.stub.php
 */
error_reporting(E_ALL);

$rf = new ReflectionFunction('closedir');
echo 'return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '-', "\n";
$p = $rf->getParameters()[0];
echo 'optional=', (int) $p->isOptional(), "\n";
echo 'default=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-', "\n";

try {
    closedir();
    echo "fail: bare without open\n";
    exit(1);
} catch (TypeError $e) {
    if ('No resource supplied' !== $e->getMessage()) {
        echo 'fail: bare noopen msg: ', $e->getMessage(), "\n";
        exit(1);
    }
}

$dir = sys_get_temp_dir().'/phpc_closedir_opt_'.getmypid();
mkdir($dir);
$dh = opendir($dir);
if (false === $dh) {
    echo "fail: opendir\n";
    exit(1);
}
readdir($dh);

try {
    closedir();
    echo "bare_ok\n";
} catch (Throwable $e) {
    echo 'fail: bare: ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

try {
    readdir($dh);
    echo "fail: expected invalid after bare close\n";
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'not a valid Directory resource')) {
        echo 'fail: after bare: ', $e->getMessage(), "\n";
        exit(1);
    }
}

$dh2 = opendir($dir);
closedir(null);
try {
    readdir($dh2);
    echo "fail: expected invalid after null close\n";
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'not a valid Directory resource')) {
        echo 'fail: after null: ', $e->getMessage(), "\n";
        exit(1);
    }
}

try {
    closedir($dh2, 1);
    echo "fail: expected ArgumentCountError\n";
    exit(1);
} catch (ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), 'at most 1 argument')) {
        echo 'fail: excess msg: ', $e->getMessage(), "\n";
        exit(1);
    }
}

rmdir($dir);
echo "ok\n";
