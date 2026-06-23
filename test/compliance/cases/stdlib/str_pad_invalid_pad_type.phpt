--TEST--
stdlib str_pad() invalid pad_type throws ValueError (#10883, ext/standard/string.c)
--FILE--
<?php
try {
    str_pad('9', 10, '0', 99);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
str_pad(): Argument #4 ($pad_type) must be STR_PAD_LEFT, STR_PAD_RIGHT, or STR_PAD_BOTH
