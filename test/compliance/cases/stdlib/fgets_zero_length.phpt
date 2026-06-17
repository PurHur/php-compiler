--TEST--
fgets(): length <= 0 throws ValueError (ext/standard/streams.c, #9347)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
fwrite($h, "ab\ncd");
rewind($h);
foreach ([0, -1] as $length) {
    try {
        fgets($h, $length);
        echo "no error for $length\n";
    } catch (Throwable $e) {
        echo get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
    rewind($h);
}
fclose($h);
--EXPECT--
ValueError
fgets(): Argument #2 ($length) must be greater than 0
ValueError
fgets(): Argument #2 ($length) must be greater than 0
