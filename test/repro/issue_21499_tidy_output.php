<?php

declare(strict_types=1);

echo 'fn_clean=', (int) function_exists('tidy_clean_repair'), "\n";
echo 'fn_out=', (int) function_exists('tidy_get_output'), "\n";
echo 'prop=', (int) property_exists('tidy', 'value'), "\n";

$t = @tidy_parse_string('<title>x</title><p>hi');
if (false === $t) {
    echo "live=host_unavailable\n";
    echo "ok\n";
    exit(0);
}
echo 'live=1', "\n";
echo 'clean=', (int) tidy_clean_repair($t), "\n";
$out = tidy_get_output($t);
echo 'out=', is_string($out) && $out !== '' ? 'str' : gettype($out), "\n";
echo 'value=', is_string($t->value) && $t->value !== '' ? 'str' : gettype($t->value), "\n";
echo "ok\n";
