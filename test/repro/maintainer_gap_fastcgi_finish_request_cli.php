<?php

declare(strict_types=1);

if (!function_exists('fastcgi_finish_request')) {
    echo "fail: function missing\n";
    exit(1);
}

echo "before\n";
$ok = fastcgi_finish_request();
if (false !== $ok) {
    echo 'fail: expected false, got '.var_export($ok, true)."\n";
    exit(1);
}
echo "after\n";
