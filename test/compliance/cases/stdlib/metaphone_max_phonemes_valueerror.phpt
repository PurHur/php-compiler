--TEST--
metaphone(): negative $max_phonemes throws ValueError (#29304)
--FILE--
<?php
try {
    metaphone('test', -1);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
metaphone(): Argument #2 ($max_phonemes) must be greater than or equal to 0
