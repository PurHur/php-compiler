--TEST--
Language: enum case instance method calls E::A->m() — trait, interface, get_class (#9720, Zend/zend_enum.c)
--FILE--
<?php
trait T {
    public function m(): string { return 'ok'; }
}
enum E {
    case A;
    use T;
}
echo E::A->m(), "\n";

interface I { public function m(): string; }
enum E2: string implements I {
    case A = 'a';
    public function m(): string { return $this->value; }
}
echo E2::A->m(), "\n";
echo get_class(E::A), "\n";
--EXPECT--
ok
a
E
