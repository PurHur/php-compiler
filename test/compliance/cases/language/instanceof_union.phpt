--TEST--
Language: instanceof with union types — (A|B) RHS (#3461)
--FILE--
<?php
interface A {}
interface B {}
class C implements A, B {}
class D {}
var_dump((new C) instanceof (A|B));
var_dump((new C) instanceof (A|D));
var_dump((new D) instanceof (A|B));
var_dump(null instanceof (A|B));
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(false)
