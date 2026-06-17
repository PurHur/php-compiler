--TEST--
fread(): length <= 0 throws ValueError (ext/standard/streams.c, #9286)
--FILE--
<?php
$h = fopen('test/bootstrap-aot/stdlib_stream_fixture/sample.txt', 'r');
if (false === $h) {
    $dir = 'test/bootstrap-aot/stdlib_stream_fixture';
    @mkdir($dir);
    file_put_contents($dir.'/sample.txt', 'x');
    $h = fopen($dir.'/sample.txt', 'r');
}
foreach ([0, -1] as $length) {
    try {
        fread($h, $length);
        echo "no error for $length\n";
    } catch (Throwable $e) {
        echo get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
}
fclose($h);
--EXPECT--
ValueError
fread(): Argument #2 ($length) must be greater than 0
ValueError
fread(): Argument #2 ($length) must be greater than 0
