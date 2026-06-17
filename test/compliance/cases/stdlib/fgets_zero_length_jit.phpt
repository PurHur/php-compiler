--TEST--
fgets(): length <= 0 throws ValueError on JIT (#9347)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
fwrite($h, "ab\ncd");
rewind($h);
$length = 0;
try {
    fgets($h, $length);
    echo "no error\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
fclose($h);
--EXPECT--
ValueError
fgets(): Argument #2 ($length) must be greater than 0
