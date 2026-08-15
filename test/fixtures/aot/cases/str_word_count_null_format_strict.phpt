--TEST--
AOT str_word_count null $format under strict_types TypeError (#31287)
--FILE--
<?php
declare(strict_types=1);
try {
    str_word_count('a b', null);
    echo "fail null format\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
str_word_count(): Argument #2 ($format) must be of type int, null given
