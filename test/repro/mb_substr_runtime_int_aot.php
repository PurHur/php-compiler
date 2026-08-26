<?php

// #34881 / #34256 — AOT mb_substr/mb_strcut runtime int offsets must match Zend.
// NestedJIT zeros rewritten params and plain `$x = $param` copies; helpers use `$x + 0`.
$i = 1;
$neg = -2;
$s = 'über';
var_dump(mb_substr('über', $i, 2));
var_dump(mb_substr($s, $i, 2));
var_dump(mb_strcut($s, $i, 2));
var_dump(mb_substr('abcdef', $i, 2));
var_dump(mb_strcut('abcdef', $i, 2));
var_dump(mb_substr('abcdef', $neg, 2));
var_dump(mb_substr('über', $neg, 2));
var_dump(mb_strcut('abcdef', $neg, 2));
