--TEST--
stdlib token_name() unknown id — returns UNKNOWN not Error (#4710, ext/tokenizer/tokenizer.c)
--FILE--
<?php
echo token_name(101), "\n";
echo token_name(ord(';')), "\n";
echo token_name(99999), "\n";
?>
--EXPECT--
UNKNOWN
UNKNOWN
UNKNOWN
