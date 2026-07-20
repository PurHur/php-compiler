--TEST--
stdlib stream_socket_client()/fsockopen()(null) DEP+coerce on 8.4 (#21446, reverts #19199, ext/standard/streamsfuncs.c)
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
stream_socket_client COERCED
fsockopen COERCED
