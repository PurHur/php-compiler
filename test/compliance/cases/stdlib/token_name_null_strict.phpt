--TEST--
stdlib token_name() null under strict_types — TypeError (#31407, ext/tokenizer/tokenizer.c)
--FILE--
<?php
declare(strict_types=1);
try {
    token_name(null);
    echo "no exception\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
token_name(): Argument #1 ($id) must be of type int, null given
