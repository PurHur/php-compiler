<?php

declare(strict_types=1);

if (function_exists('fastcgi_finish_request')) {
    echo "fail: function advertised on CLI\n";
    exit(1);
}

echo "ok\n";
