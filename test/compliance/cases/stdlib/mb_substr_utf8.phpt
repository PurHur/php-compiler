--TEST--
stdlib mb_substr/mb_strpos/mb_strtolower/mb_strtoupper UTF-8 (VM, #3239)
--FILE--
<?php
echo (int) function_exists('mb_substr'), "\n";
echo (int) function_exists('mb_strpos'), "\n";
echo (int) function_exists('mb_strtolower'), "\n";
echo (int) function_exists('mb_strtoupper'), "\n";
echo mb_substr('café', 0, 2, 'UTF-8'), "\n";
echo mb_substr('café', 1, 2, 'UTF-8'), "\n";
echo mb_substr('αβγ', -2, null, 'UTF-8'), "\n";
echo mb_substr('αβγ', 0, -1, 'UTF-8'), "\n";
var_dump(mb_strpos('αβγδ', 'γ', 0, 'UTF-8'));
var_dump(mb_strpos('αβγδ', 'ε', 0, 'UTF-8'));
echo mb_strtolower('HELLO', 'UTF-8'), "\n";
echo mb_strtoupper('hello', 'UTF-8'), "\n";
--EXPECT--
1
1
1
1
ca
af
βγ
αβ
int(2)
bool(false)
hello
HELLO
