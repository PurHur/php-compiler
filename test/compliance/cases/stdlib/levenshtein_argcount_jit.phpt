--TEST--
stdlib levenshtein() JIT — too many arguments ArgumentCountError (#10431, levenshtein.c)
--JIT--
--FILE--
<?php
function check_levenshtein_argcount(): void
{
    try {
        levenshtein('abc', 'abc', 1, 2, 3, 4);
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
check_levenshtein_argcount();
--EXPECT--
ArgumentCountError: levenshtein() expects at most 5 arguments, 6 given
