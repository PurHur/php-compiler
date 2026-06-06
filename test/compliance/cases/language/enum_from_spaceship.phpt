--TEST--
Language: backed enum <=> from()/tryFrom() singleton identity (#7006, Zend/zend_enum.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

var_dump(E::A <=> E::from(1));
var_dump(E::from(1) <=> E::A);
var_dump(E::A <=> E::tryFrom(1));
--EXPECT--
int(0)
int(0)
int(0)
