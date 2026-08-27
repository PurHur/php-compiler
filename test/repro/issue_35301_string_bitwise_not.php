<?php
/**
 * #35301 — unary ~ on string must link __string__bitwiseNot under thin AOT.
 * Leftover of #14823 / #24513 (NestedJIT helper registered but body deferred).
 *
 * php-src: Zend/zend_operators.c — bitwise_not_function string path
 */
function s(string $x): string
{
    return $x;
}
echo bin2hex(~'a'), PHP_EOL;
echo bin2hex(~s('a')), PHP_EOL;
echo bin2hex(~'5'), PHP_EOL;
var_dump(~'a');
