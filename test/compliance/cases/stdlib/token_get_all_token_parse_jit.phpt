--TEST--
stdlib token_get_all() TOKEN_PARSE preserves T_WHITESPACE JIT (#9775, ext/tokenizer/tokenizer.c)
--FILE--
<?php
$src = '<?php echo 1;';
$tokens = token_get_all($src, TOKEN_PARSE);
echo count($tokens), "\n";
foreach ($tokens as $i => $t) {
    if (is_array($t)) {
        echo "$i:", token_name($t[0]), "\n";
    } else {
        echo "$i:lit\n";
    }
}
--EXPECT--
5
0:T_OPEN_TAG
1:T_ECHO
2:T_WHITESPACE
3:T_LNUMBER
4:lit
--CREDITS--
PurHur/php-compiler issue #9775
