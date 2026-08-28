<?php
// #13686: callable $param must accept ['Class','method'] — Zend/zend_callables.c.
class G09C13686 {
    public static function m(string $s): void {
        echo $s;
    }
}
function g09_accept_13686(callable $c): void {
    $c('hi');
}
g09_accept_13686([G09C13686::class, 'm']);
echo "ok\n";
