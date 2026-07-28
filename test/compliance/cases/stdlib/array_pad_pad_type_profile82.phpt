--TEST--
ARRAY_PAD_* + array_pad() 4th arg withheld on PROFILE=8.2 (#22786, #24002)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
foreach (['ARRAY_PAD_LEFT', 'ARRAY_PAD_RIGHT', 'ARRAY_PAD_BOTH'] as $c) {
    echo $c, '=', defined($c) ? '1' : '0', "\n";
}
echo (new ReflectionFunction('array_pad'))->getNumberOfParameters(), "\n";
try {
    array_pad([1], 3, 0, 0);
    echo "no error\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
ARRAY_PAD_LEFT=0
ARRAY_PAD_RIGHT=0
ARRAY_PAD_BOTH=0
3
ArgumentCountError
array_pad() expects exactly 3 arguments, 4 given
