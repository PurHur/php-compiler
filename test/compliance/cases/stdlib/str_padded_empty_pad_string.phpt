--TEST--
stdlib str_padded() — empty pad_string ValueError (#7044)
--FILE--
<?php
try {
    str_padded('hi', 5, '');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
str_padded(): Argument #3 ($pad_string) must be a non-empty string
