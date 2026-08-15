--TEST--
stdlib metaphone(null $max_phonemes) JIT TypeError under strict_types (#31230, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    metaphone('hello', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
metaphone(): Argument #2 ($max_phonemes) must be of type int, null given
