<?php

declare(strict_types=1);

if (function_exists('mb_str_pad')) {
    echo "fail\n";
    exit(1);
}
echo "ok\n";
