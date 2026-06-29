--TEST--
AOT: stream_notification_callback() — not advertised (php-src parity, #13750)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('stream_notification_callback') ? "yes\n" : "no\n";
--EXPECT--
no
