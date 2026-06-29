<?php

declare(strict_types=1);

if (function_exists('stream_notification_callback')) {
    echo "fail: stream_notification_callback advertised without php-src registration\n";
    exit(1);
}

echo "ok\n";
