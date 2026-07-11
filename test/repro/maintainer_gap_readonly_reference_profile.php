<?php
declare(strict_types=1);

if (function_exists('readonly')) {
    echo "fail: function_exists\n";
    exit(1);
}

echo "ok\n";
