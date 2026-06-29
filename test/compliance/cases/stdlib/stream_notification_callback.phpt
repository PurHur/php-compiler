--TEST--
stdlib stream_notification_callback() — not advertised (php-src parity, #13750)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('stream_notification_callback') ? "fail\n" : "ok\n";
--EXPECT--
ok
