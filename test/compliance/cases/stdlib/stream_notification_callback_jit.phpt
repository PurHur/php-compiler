--TEST--
JIT: stream_notification_callback() global notifier (#6055)
--FILE--
<?php
var_export(function_exists('stream_notification_callback'));
echo "\n";
$seen = 0;
stream_notification_callback(function (int $code) use (&$seen): void {
    if (STREAM_NOTIFY_COMPLETED === $code) {
        ++$seen;
    }
});
$src = fopen('php://memory', 'r+');
$dst = fopen('php://memory', 'w+');
fwrite($src, 'ab');
rewind($src);
stream_copy_to_stream($src, $dst);
fclose($src);
fclose($dst);
stream_notification_callback(null);
echo $seen, "\n";
--EXPECT--
true
1
