--TEST--
stdlib parse_str() one-arg ArgumentCountError message (#17873, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

foreach ([0, 1] as $argc) {
    try {
        if (0 === $argc) {
            parse_str();
        } else {
            parse_str('a=1');
        }
        echo "fail: uncaught argc={$argc}\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
parse_str() expects exactly 2 arguments, 0 given
parse_str() expects exactly 2 arguments, 1 given
