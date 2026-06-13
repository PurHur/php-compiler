--TEST--
stdlib stream_notification_callback() — register global notifier (#6055)
--FILE--
<?php
var_export(function_exists('stream_notification_callback'));
echo "\n";
var_export(stream_notification_callback(null));
echo "\n";
$events = [];
$cb = function (int $code, int $sev, string $msg, int $mc, int $bt, int $bm) use (&$events): void {
    $events[] = $code;
};
$prev = stream_notification_callback($cb);
var_export(null === $prev);
echo "\n";
$src = fopen('php://memory', 'r+');
$dst = fopen('php://memory', 'w+');
fwrite($src, str_repeat('x', 10000));
rewind($src);
stream_copy_to_stream($src, $dst);
fclose($src);
fclose($dst);
var_export(in_array(STREAM_NOTIFY_FILE_SIZE_IS, $events, true));
echo "\n";
var_export(in_array(STREAM_NOTIFY_PROGRESS, $events, true));
echo "\n";
var_export(in_array(STREAM_NOTIFY_COMPLETED, $events, true));
echo "\n";
$cleared = stream_notification_callback(null);
var_export(null === $cleared);
echo "\n";
var_export(null === stream_notification_callback(null));
echo "\n";
try {
    stream_notification_callback(123);
    echo "no error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
NULL
true
true
true
true
false
true
stream_notification_callback(): Argument #1 ($callback) must be a valid callback
