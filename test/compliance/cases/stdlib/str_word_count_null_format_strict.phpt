--TEST--
stdlib str_word_count null $format under strict_types TypeError (#31287, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
try {
    str_word_count('a b', null);
    echo "fail null format\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo str_word_count('a b'), "\n";
echo str_word_count('a b', 0), "\n";
--EXPECT--
str_word_count(): Argument #2 ($format) must be of type int, null given
2
2
