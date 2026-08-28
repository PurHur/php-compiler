--TEST--
AOT: callable parameter invoke accepts static array callable (#13686, Zend/zend_callables.c)
--FILE--
<?php
class C {
    public static function m(string $s): void { echo $s; }
}
function accept(callable $c): void { $c('hi'); }
accept([C::class, 'm']);
echo "ok\n";
--EXPECT--
hi
ok
