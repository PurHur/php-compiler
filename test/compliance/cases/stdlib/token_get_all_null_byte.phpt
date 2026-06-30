--TEST--
stdlib token_get_all() null byte after $ — literal dollar + T_BAD_CHARACTER (#13896, ext/tokenizer/tokenizer.c)
--FILE--
<?php
$src = "<?php \$\0 = 1;";
$tokens = token_get_all($src);
echo ($tokens[1] === '$' ? 'dollar' : 'bad_dollar'), "\n";
echo (is_array($tokens[2]) && T_BAD_CHARACTER === $tokens[2][0] ? 'bad_char' : 'bad_bad_char'), "\n";
?>
--EXPECT--
dollar
bad_char
