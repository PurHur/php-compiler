--TEST--
socket_strerror/last_error/clear_error thin AOT NestedJIT (#31270)
--FILE--
<?php
echo socket_strerror(0), "\n";
$msg = socket_strerror(111);
echo (false !== strpos($msg, 'refused') || false !== strpos($msg, 'Connection') ? 'strerror_ok' : $msg), "\n";
echo 'host=', socket_strerror(-10001), "\n";
echo 'bare=', socket_last_error(), "\n";
socket_clear_error();
echo 'gclear=', socket_last_error(), "\n";
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    return;
}
echo 'sock=', socket_last_error($pair[0]), "\n";
socket_clear_error($pair[0]);
echo 'cleared=', socket_last_error($pair[0]), "\n";
socket_close($pair[0]);
socket_close($pair[1]);
echo "error_helpers_linked_ok\n";
?>
--EXPECT--
Success
strerror_ok
host=Unknown host
bare=0
gclear=0
sock=0
cleared=0
error_helpers_linked_ok
