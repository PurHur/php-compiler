--TEST--
stdlib token_name(TOKEN_PARSE) returns UNKNOWN not TOKEN_PARSE (#14925, ext/tokenizer/tokenizer_data.c)
--FILE--
<?php
echo token_name(TOKEN_PARSE), "\n";
--EXPECT--
UNKNOWN
