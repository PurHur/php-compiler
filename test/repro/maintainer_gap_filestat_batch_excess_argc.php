<?php

/**
 * #30554 — filestat ownership/meta/fnmatch excess argc → ArgumentCountError.
 */
error_reporting(E_ALL);

$cases = [
    'umask(0, "x")',
    'chown("/tmp", "root", "x")',
    'chgrp("/tmp", "root", "x")',
    'clearstatcache(true, "/tmp", "x")',
    'stat("/tmp", "x")',
    'lstat("/tmp", "x")',
    'fileinode("/tmp", "x")',
    'fileowner("/tmp", "x")',
    'filegroup("/tmp", "x")',
    'fileperms("/tmp", "x")',
    'fnmatch("*", "a", 0, "x")',
];
foreach ($cases as $code) {
    try {
        eval($code.';');
        echo "$code => NO_THROW\n";
    } catch (Throwable $e) {
        echo $code, ' => ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
