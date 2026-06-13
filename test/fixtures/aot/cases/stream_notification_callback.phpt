--TEST--
AOT: stream_notification_callback() exists and clears notifier (#6055)
--FILE--
<?php
echo function_exists('stream_notification_callback') ? "yes\n" : "no\n";
var_export(stream_notification_callback(null));
echo "\n";
var_export(stream_notification_callback(null));
echo "\n";
--EXPECT--
yes
NULL
NULL
