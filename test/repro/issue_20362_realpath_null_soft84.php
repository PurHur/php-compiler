<?php
// Repro #20362 — realpath(null) soft-null under PROFILE=8.4 (Zend 8.4.23 DEP+coerce).
// VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20362_realpath_null_soft84.php
// AOT: PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/r test/repro/issue_20362_realpath_null_soft84.php && /tmp/r
error_reporting(E_ALL & ~E_DEPRECATED);
try {
    $realNull = realpath(null);
    $realEmpty = realpath('');
    echo 'ok:', \gettype($realNull), ':', ($realNull === $realEmpty ? 'match' : 'mismatch'), "\n";
} catch (Throwable $e) {
    echo $e::class, ':', $e->getMessage(), "\n";
}
