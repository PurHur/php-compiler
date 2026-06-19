--TEST--
Language: consecutive match() — first default arm must not clobber phi slot (#9856, Zend/zend_execute.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; case B = 2; }

var_dump(match (1) {
    E::A => 'a',
    default => 'd',
});

var_dump(match (E::A) {
    1 => 'i',
    default => 'd',
});
?>
--EXPECT--
string(1) "d"
string(1) "d"
