<?php
/**
 * Maintainer repro: #26742 — PHP 8.4 pcntl_* phantom on default / Zend 8.2 profile.
 *
 * Default (unset PHP_COMPILER_PROFILE): five APIs must be function_exists-false.
 * Forward: PHP_COMPILER_PROFILE=8.4 php bin/vm.php this-file → all Y.
 */
declare(strict_types=1);

foreach ([
    'pcntl_getcpu',
    'pcntl_getcpuaffinity',
    'pcntl_setcpuaffinity',
    'pcntl_setns',
    'pcntl_waitid',
    'pcntl_fork',
] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'Y' : 'N', "\n";
}
