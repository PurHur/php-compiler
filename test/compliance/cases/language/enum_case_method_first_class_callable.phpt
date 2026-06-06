--TEST--
Language: enum case method first-class callable E::A->f(...) (#6845, zend_closures.c)
--FILE--
<?php
enum E {
    case A;
    public function f(): string { return 'a'; }
}
$c = E::A->f(...);
echo $c(), "\n";
--EXPECT--
a
