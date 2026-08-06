--TEST--
stdlib preg_split/preg_grep unknown flags masked like Zend — no LogicException (#27946, ext/pcre)
--FILE--
<?php
foreach ([
    'split' => fn() => preg_split('/a/', 'a', -1, 999),
    'grep' => fn() => preg_grep('/a/', [1, 'a'], 999),
    'grep998' => fn() => preg_grep('/a/', [1, 'a'], 998),
] as $name => $fn) {
    try {
        echo $name, ':', json_encode($fn()), "\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    $m = null;
    preg_match('/a/', 'a', $m, 999);
    echo "match:ok\n";
} catch (Throwable $e) {
    echo 'match:', get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
split:[]
grep:[1]
grep998:{"1":"a"}
match:ValueError:preg_match(): Argument #4 ($flags) must be a PREG_* constant
