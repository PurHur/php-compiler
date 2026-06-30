--TEST--
stdlib stream_get_line() too-few-args throws ArgumentCountError (#14220, ext/standard/streamsfuncs.c)
--FILE--
<?php
$handle = fopen('php://memory', 'r+');
try {
    stream_get_line($handle);
    echo "no_throw\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
stream_get_line() expects at least 2 arguments, 1 given
