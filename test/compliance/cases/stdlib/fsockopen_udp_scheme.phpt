--TEST--
Stdlib: fsockopen() udp://host + port merges scheme (issue #25779)
--FILE--
<?php
$errno = 0;
$errstr = '';
$fp = @fsockopen('udp://127.0.0.1', 9, $errno, $errstr, 0.2);
echo $fp ? "udp_ok\n" : "udp_fail:$errno:$errstr\n";
if ($fp) {
    fclose($fp);
}

$errno = 0;
$errstr = '';
$fp = @fsockopen('tcp://127.0.0.1', 9, $errno, $errstr, 0.2);
echo $fp ? "tcp_ok\n" : (0 !== $errno ? "tcp_refused\n" : "tcp_fail:$errno:$errstr\n");
if ($fp) {
    fclose($fp);
}

$errno = 0;
$errstr = '';
$fp = @fsockopen('unix:///tmp/php-compiler-no-such-25779.sock', -1, $errno, $errstr, 0.2);
echo $fp ? "unix_ok\n" : "unix_fail:$errno:$errstr\n";
if ($fp) {
    fclose($fp);
}

$errno = 0;
$errstr = '';
$fp = @stream_socket_client('unix:///tmp/php-compiler-no-such-25779.sock', $errno, $errstr, 0.2);
echo $fp ? "ssc_unix_ok\n" : "ssc_unix_fail:$errno:$errstr\n";
if ($fp) {
    fclose($fp);
}
--EXPECT--
udp_ok
tcp_refused
unix_fail:2:No such file or directory
ssc_unix_fail:2:No such file or directory
