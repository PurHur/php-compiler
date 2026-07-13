<?php

declare(strict_types=0);

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

$fail = 0;

if (false !== @opendir(null)) {
    echo "opendir(null) expected false\n";
    ++$fail;
}
if (false !== @mkdir(null)) {
    echo "mkdir(null) expected false\n";
    ++$fail;
}
if (false !== @rmdir(null)) {
    echo "rmdir(null) expected false\n";
    ++$fail;
}
if (false !== @chdir(null)) {
    echo "chdir(null) expected false\n";
    ++$fail;
}

echo 0 === $fail ? "ok\n" : "fail\n";
