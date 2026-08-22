<?php
/**
 * #33719 — AOT array_reduce must keep carry across `$c + ($v === null ? … : $v)`.
 */
$a = [null, 1, null, 2];
echo 'add:', array_reduce($a, fn ($c, $v) => $c + ($v === null ? 10 : $v), 0), PHP_EOL;
echo 'ints:', array_reduce([1, 2, 3], fn ($c, $v) => $c + ($v === null ? 10 : $v), 0), PHP_EOL;
echo 'asvar:', array_reduce($a, function ($c, $v) {
    $as = $v === null ? 10 : $v;

    return $c + $as;
}, 0), PHP_EOL;
echo 'plain:', array_reduce([1, 2, 3], fn ($c, $v) => $c + $v, 0), PHP_EOL;
echo 'ret:', array_reduce($a, fn ($c, $v) => ($v === null ? 10 : $v), 0), PHP_EOL;
