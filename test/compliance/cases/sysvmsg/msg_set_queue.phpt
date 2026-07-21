--TEST--
sysvmsg msg_set_queue() updates queue attrs from msg_stat_queue array (#21633)
--SKIPIF--
<?php if (!function_exists('msg_get_queue') || !function_exists('msg_set_queue')) { print 'skip sysvmsg unavailable'; } ?>
--FILE--
<?php
echo function_exists('msg_set_queue') ? "fn\n" : "no-fn\n";
$path = tempnam(sys_get_temp_dir(), 'phpc_msg_set_');
$key = ftok($path, 's');
@unlink($path);
$q = @msg_get_queue($key, 0666);
if (false === $q) {
    echo "get-fail\n";
    exit(0);
}
$stat = msg_stat_queue($q);
if (!is_array($stat)) {
    echo "stat-fail\n";
    msg_remove_queue($q);
    exit(0);
}
echo msg_set_queue($q, $stat) ? "set\n" : "set-fail\n";
$stat2 = msg_stat_queue($q);
echo (is_array($stat2) && isset($stat2['msg_qbytes']) && $stat2['msg_qbytes'] === $stat['msg_qbytes'])
    ? "qbytes-ok\n"
    : "qbytes-bad\n";
echo msg_remove_queue($q) ? "rm\n" : "rm-fail\n";
echo "done\n";
?>
--EXPECT--
fn
set
qbytes-ok
rm
done
