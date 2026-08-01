--TEST--
JIT token_get_all()/PhpToken::tokenize() TOKEN_PARSE ParseError on unclosed (#26671, ext/tokenizer/tokenizer.c)
--FILE--
<?php
require __DIR__.'/token_get_all_token_parse_parseerror.php';
--EXPECT--
ParseError:Unclosed '{'
ParseError:Unclosed '('
ParseError:Unclosed '{'
ok:5
phptoken:ParseError:Unclosed '{'
--CREDITS--
PurHur/php-compiler issue #26671
