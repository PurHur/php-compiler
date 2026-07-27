--TEST--
stdlib stream_socket_client()/fsockopen()(null) TypeError (#23823, reverts #21446, ext/standard/streamsfuncs.c)
--FILE--
<?php
putenv('PHP_COMPILER_PROFILE=8.4');
$_ENV['PHP_COMPILER_PROFILE'] = '8.4';
$_SERVER['PHP_COMPILER_PROFILE'] = '8.4';
foreach (['stream_socket_client', 'fsockopen'] as $fn) {
    try {
        $fn(null);
        echo $fn, " COERCED\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
stream_socket_client: stream_socket_client(): Argument #1 ($address) must be of type string, null given
fsockopen: fsockopen(): Argument #1 ($hostname) must be of type string, null given

