<?php

declare(strict_types=1);

/**
 * Maintainer repro: get_error_handler()/get_exception_handler() on forward 8.4 profile (#17644).
 *
 * ./script/docker-exec.sh -- bash -lc 'PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_get_error_handler_forward84.php'
 */

if ('8.4' !== getenv('PHP_COMPILER_PROFILE')) {
    echo "skip: requires PHP_COMPILER_PROFILE=8.4\n";
    exit(0);
}

if (!function_exists('get_error_handler') || !function_exists('get_exception_handler')) {
    echo "fail: function_exists false\n";
    exit(1);
}

set_error_handler(static fn () => true);
$h = get_error_handler();
if (!is_callable($h)) {
    echo "fail: get_error_handler not callable after set\n";
    exit(1);
}
restore_error_handler();
if (null !== get_error_handler()) {
    echo "fail: get_error_handler should be null after restore\n";
    exit(1);
}

set_exception_handler(static fn () => true);
$ex = get_exception_handler();
if (!is_callable($ex)) {
    echo "fail: get_exception_handler not callable after set\n";
    exit(1);
}
restore_exception_handler();
if (null !== get_exception_handler()) {
    echo "fail: get_exception_handler should be null after restore\n";
    exit(1);
}

echo "ok\n";
