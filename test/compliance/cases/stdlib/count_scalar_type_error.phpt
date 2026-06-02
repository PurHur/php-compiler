--TEST--
stdlib count() TypeError on non-countable scalar (issue #4501, ext/standard/array.c)
--FILE--
<?php
try {
    count('abc');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "done\n";
--EXPECT--
TypeError: count(): Argument #1 ($value) must be of type Countable|array, string given
done
