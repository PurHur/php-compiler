--TEST--
stdlib strpbrk() empty $characters — ValueError message (#13389, ext/standard/string.c)
--FILE--
<?php
try {
    strpbrk('hello', '');
} catch (\ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strpbrk(): Argument #2 ($characters) must be a non-empty string
