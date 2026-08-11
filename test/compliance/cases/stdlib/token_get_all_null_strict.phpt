--TEST--
stdlib token_get_all(null) TypeError under strict_types (#30257, ext/tokenizer/tokenizer.c)
--FILE--
<?php
declare(strict_types=1);
try {
    token_get_all(null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:token_get_all(): Argument #1 ($code) must be of type string, null given
