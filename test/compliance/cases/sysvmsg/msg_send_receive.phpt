--TEST--
sysvmsg msg_get_queue/send/receive/remove round-trip (#3666)
--SKIPIF--
<?php if (!function_exists('msg_get_queue')) { print 'skip sysvmsg unavailable'; } ?>
--FILE--
<?php
echo function_exists('msg_get_queue') ? "fn\n" : "no-fn\n";
$path = tempnam(sys_get_temp_dir(), 'phpc_msg_');
$key = ftok($path, 'm');
@unlink($path);
$q = @msg_get_queue($key, 0666);
if (false === $q) {
    echo "get-fail\n";
    exit(0);
}
echo get_class($q), "\n";
echo msg_send($q, 1, 'hello', false) ? "send\n" : "send-fail\n";
$type = 0;
$msg = null;
echo msg_receive($q, 0, $type, 1024, $msg, false) ? "recv\n" : "recv-fail\n";
echo $type, "\n";
echo $msg, "\n";
$stat = msg_stat_queue($q);
echo is_array($stat) ? "stat\n" : "stat-fail\n";
echo msg_remove_queue($q) ? "rm\n" : "rm-fail\n";
echo msg_queue_exists($key) ? "exists\n" : "gone\n";
echo "done\n";
?>
--EXPECT--
fn
SysvMessageQueue
send
recv
1
hello
stat
rm
gone
done
