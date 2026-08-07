<?php
/**
 * Issue #28432 — AOT msg_get_queue/send/receive/remove must not refuse (peer #3666 VM).
 */
$key = 0x28432;
$q = @msg_get_queue($key, 0666);
echo $q === false ? "false\n" : "ok\n";
if ($q !== false) {
    echo @msg_send($q, 1, 'hi', false) ? "snd\n" : "snd_fail\n";
    $type = 0;
    $msg = null;
    echo @msg_receive($q, 1, $type, 100, $msg, false) ? "rcv\n" : "rcv_fail\n";
    echo ($msg === 'hi' && $type === 1) ? "hi\n" : "bad\n";
    echo @msg_remove_queue($q) ? "rm\n" : "rm_fail\n";
}
