<?php

declare(strict_types=1);

if (!function_exists('get_browser')) {
    echo "missing\n";
    exit(1);
}
$r = @get_browser(null);
echo $r === false ? "ok\n" : "not_false\n";
