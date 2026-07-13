--TEST--
stdlib token_get_all() short open tag + NUL — single T_INLINE_HTML (#18468, ext/tokenizer/tokenizer.c)
--FILE--
<?php
$tokens = token_get_all("<? \0");
echo count($tokens), "\n";
echo is_array($tokens[0]) && T_INLINE_HTML === $tokens[0][0] ? 'inline' : 'not_inline', "\n";
echo $tokens[0][1] === "<? \0" ? 'text' : 'bad_text', "\n";
?>
--EXPECT--
1
inline
text
--CREDITS--
PurHur/php-compiler issue #18468
