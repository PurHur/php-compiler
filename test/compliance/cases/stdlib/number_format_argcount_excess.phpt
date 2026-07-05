--TEST--
stdlib number_format() fifth spurious argument ArgumentCountError (#16330, ext/standard/math.c)
--FILE--
<?php
try {
    number_format(1.5, 2, '.', '', 99);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError: number_format() expects at most 4 arguments, 5 given
