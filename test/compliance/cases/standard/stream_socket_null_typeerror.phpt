--TEST--
stdlib stream_socket_client()/fsockopen()(null) TypeError (#19199, ext/standard/streamsfuncs.c)
--FILE--
<?php
putenv('PHP_COMPILER_PROFILE=8.4');
$_ENV['PHP_COMPILER_PROFILE'] = '8.4';
$_SERVER['PHP_COMPILER_PROFILE'] = '8.4';
foreach (['stream_socket_client', 'fsockopen'] as $fn) {
    try {
        $fn(null);
        echo $fn, ": fail\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
stream_socket_client: stream_socket_client(): Argument #1 ($remote_socket) must be of type string, null given
fsockopen: fsockopen(): Argument #1 ($hostname) must be of type string, null given
