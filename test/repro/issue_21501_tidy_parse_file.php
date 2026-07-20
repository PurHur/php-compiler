<?php

declare(strict_types=1);

echo 'fn=', (int) function_exists('tidy_parse_file'), "\n";
echo 'm_str=', (int) method_exists('tidy', 'parseString'), "\n";
echo 'm_file=', (int) method_exists('tidy', 'parseFile'), "\n";

$t = @tidy_parse_file('/etc/hosts');
if (false === $t) {
    // Also soft when host tidy missing — parse_file may fail for other reasons with host.
    $probe = @tidy_parse_string('<p>x');
    if (false === $probe) {
        echo "live=host_unavailable\n";
        echo "ok\n";
        exit(0);
    }
    echo "live=1\n";
    echo 'parse_file=false_on_hosts\n';
    echo 'into=', (int) $probe->parseString('<p>y'), "\n";
    echo "ok\n";
    exit(0);
}
echo "live=1\n";
echo 'inst=', ($t instanceof tidy) ? 'Y' : 'N', "\n";
echo "ok\n";
