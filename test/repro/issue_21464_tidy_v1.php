<?php

declare(strict_types=1);

echo 'fn=', (int) function_exists('tidy_parse_string'), "\n";
echo 'class=', (int) class_exists('tidy'), "\n";
echo 'ext=', (int) extension_loaded('tidy'), "\n";

$t = @tidy_parse_string('<title>x</title><p>hi');
if (false === $t) {
    echo "parse=host_unavailable\n";
    echo "ok\n";
    exit(0);
}
echo 'parse=', ($t instanceof tidy) ? 'tidy' : gettype($t), "\n";
echo 'repair=', (int) $t->cleanRepair(), "\n";
echo "ok\n";
