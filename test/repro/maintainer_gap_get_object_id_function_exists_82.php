<?php

declare(strict_types=1);

if (function_exists('get_object_id')) {
    echo "fail\n";
    exit(1);
}
echo "ok\n";
