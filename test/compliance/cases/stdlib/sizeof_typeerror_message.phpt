--TEST--
stdlib sizeof() TypeError cites sizeof() not count() (#15003)
--FILE--
<?php
try {
    sizeof('x');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    count('x');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
sizeof(): Argument #1 ($value) must be of type Countable|array, string given
count(): Argument #1 ($value) must be of type Countable|array, string given
