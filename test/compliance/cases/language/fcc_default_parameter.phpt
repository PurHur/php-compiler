--TEST--
Language: first-class callable default parameter (PHP 8.5 fcc_in_const_expr, #9170)
--FILE--
<?php
class C {
    public function f(Closure $c = strlen(...)): int {
        return $c('abc');
    }
}
echo (new C)->f(), "\n";

class S {
    public static function id(string $s): string {
        return $s;
    }
    public function g(Closure $c = S::id(...)): string {
        return $c('ok');
    }
}
echo (new S)->g(), "\n";
?>
--EXPECT--
3
ok
