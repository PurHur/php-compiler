--TEST--
Stdlib: stream_socket_server() — TCP loopback bind via VmStreamSocketNative (#4993)
--FILE--
<?php
$errno = 0;
$errstr = '';
$s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
var_dump(is_resource($s));
echo $errno, "\n";
echo $errstr === '' ? "ok\n" : "err\n";
if (is_resource($s)) {
    fclose($s);
    echo "closed\n";
}
--EXPECT--
bool(true)
0
ok
closed
