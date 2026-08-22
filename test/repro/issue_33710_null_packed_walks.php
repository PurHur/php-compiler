<?php
// AOT: packed walks must keep TYPE_NULL (#33710 peer #33705).
$a = [null, 1, null, 2];
echo 'implode:', implode(',', [null, 'a', null, 'b']), PHP_EOL;
echo 'slice:', json_encode(array_slice($a, 0)), PHP_EOL;
echo 'str_replace:', json_encode(str_replace('a', 'b', [null, 'a', null])), PHP_EOL;
echo 'substr_replace:', json_encode(substr_replace([null, 'abc', null], 'X', 0, 1)), PHP_EOL;
// Visit count — arrow `$v === null` is a separate NestedJIT compare quirk; use a full closure.
$n = 0;
array_reduce($a, static function ($c, $v) use (&$n) {
    ++$n;

    return $c;
}, 0);
echo 'reduce_visits:', $n, PHP_EOL;
