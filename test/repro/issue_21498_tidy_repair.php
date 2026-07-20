<?php

declare(strict_types=1);

echo 'fn_str=', (int) function_exists('tidy_repair_string'), "\n";
echo 'fn_file=', (int) function_exists('tidy_repair_file'), "\n";
echo 'm_str=', (int) method_exists('tidy', 'repairString'), "\n";
echo 'm_file=', (int) method_exists('tidy', 'repairFile'), "\n";

$out = @tidy_repair_string('<title>x</title><p>hi');
if (false === $out) {
    echo "repair=host_unavailable\n";
    echo "ok\n";
    exit(0);
}
echo 'repair=', is_string($out) && $out !== '' ? 'str' : gettype($out), "\n";
$via = @tidy::repairString('<p>y');
echo 'static=', is_string($via) && $via !== '' ? 'str' : (false === $via ? 'false' : gettype($via)), "\n";
echo "ok\n";
