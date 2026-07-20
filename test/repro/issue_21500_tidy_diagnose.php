<?php

declare(strict_types=1);

echo 'fn_diag=', (int) function_exists('tidy_diagnose'), "\n";
echo 'fn_err=', (int) function_exists('tidy_get_error_buffer'), "\n";
echo 'm_diag=', (int) method_exists('tidy', 'diagnose'), "\n";
echo 'prop=', (int) property_exists('tidy', 'errorBuffer'), "\n";

$t = @tidy_parse_string('<title>x</title><p>hi');
if (false === $t) {
    echo "live=host_unavailable\n";
    echo "ok\n";
    exit(0);
}
echo "live=1\n";
echo 'diag=', (int) tidy_diagnose($t), "\n";
$buf = tidy_get_error_buffer($t);
echo 'buf=', is_string($buf) ? 'str' : (false === $buf ? 'false' : gettype($buf)), "\n";
echo "ok\n";
