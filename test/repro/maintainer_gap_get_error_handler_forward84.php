<?php

declare(strict_types=1);

if (!function_exists('get_error_handler') || !function_exists('get_exception_handler')) {
    fwrite(STDERR, "FAIL: handler introspection builtins missing\n");
    exit(1);
}

function err_handler(int $errno, string $errstr): bool
{
    return true;
}

function ex_handler(Throwable $e): void
{
}

$beforeErr = get_error_handler();
echo 'err_before_null=', var_export(null === $beforeErr, true), "\n";

set_error_handler('err_handler');
$activeErr = get_error_handler();
echo 'err_active=', var_export(is_string($activeErr) && 'err_handler' === $activeErr, true), "\n";
restore_error_handler();
$afterErr = get_error_handler();
echo 'err_after_null=', var_export(null === $afterErr, true), "\n";

$beforeEx = get_exception_handler();
echo 'ex_before_null=', var_export(null === $beforeEx, true), "\n";

set_exception_handler('ex_handler');
$activeEx = get_exception_handler();
echo 'ex_active=', var_export(is_string($activeEx) && 'ex_handler' === $activeEx, true), "\n";
restore_exception_handler();
$afterEx = get_exception_handler();
echo 'ex_after_null=', var_export(null === $afterEx, true), "\n";
