--TEST--
Language: match() default arm with enum subject preserves enum object (#9755, Zend/zend_execute.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
function tag(E $e): string {
    return match ($e) { E::A => 'a', default => 'other' };
}
var_dump(tag(E::B));

function f(E $e): int { return match($e) { E::A => 1, E::B => 2 }; }
var_dump(f(E::A));

function other(E $e): string {
    return 'other';
}
var_dump(match (E::B) { E::A => 'a', default => other(E::B) });
--EXPECT--
string(5) "other"
int(1)
string(5) "other"
