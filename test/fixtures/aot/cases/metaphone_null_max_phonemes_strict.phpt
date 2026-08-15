--TEST--
AOT: metaphone(null $max_phonemes) TypeError under strict_types (#31230, ext/standard/string.c)
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
