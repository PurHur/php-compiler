<?php

declare(strict_types=1);

if (!defined('RecursiveIteratorIterator::LEAVES_ONLY')) {
    echo "fail: defined(RecursiveIteratorIterator::LEAVES_ONLY) false\n";
    exit(1);
}

$riiConstants = (new ReflectionClass('RecursiveIteratorIterator'))->getConstants();
if (!isset($riiConstants['LEAVES_ONLY'], $riiConstants['SELF_FIRST'], $riiConstants['CHILD_FIRST'], $riiConstants['CATCH_GET_CHILD'])) {
    echo 'fail: ReflectionClass(RecursiveIteratorIterator)->getConstants() '.var_export($riiConstants, true)."\n";
    exit(1);
}

if (!defined('FilesystemIterator::CURRENT_AS_PATHNAME')) {
    echo "fail: defined(FilesystemIterator::CURRENT_AS_PATHNAME) false\n";
    exit(1);
}

if (!defined('FilesystemIterator::SKIP_DOTS')) {
    echo "fail: defined(FilesystemIterator::SKIP_DOTS) false\n";
    exit(1);
}

$fsConstants = (new ReflectionClass('FilesystemIterator'))->getConstants();
if (!isset($fsConstants['SKIP_DOTS'], $fsConstants['CURRENT_AS_PATHNAME'])) {
    echo 'fail: ReflectionClass(FilesystemIterator)->getConstants() '.var_export($fsConstants, true)."\n";
    exit(1);
}

echo "ok\n";
