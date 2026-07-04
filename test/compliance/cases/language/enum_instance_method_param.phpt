--TEST--
Language: enum-typed instance method parameter — inline new receiver (#16227, Zend/zend_execute.c)
--FILE--
<?php
enum E: string {
    case A = 'a';
}

class C {
    public function f(E $e): void {
        echo get_class($e), "\n";
    }
    public function g(E|int $x): void {
        echo is_object($x) ? get_class($x) : (string) $x, "\n";
    }
}

(new C())->f(E::A);
(new C())->g(E::A);
(new C())->g(1);
?>
--EXPECT--
E
E
1
