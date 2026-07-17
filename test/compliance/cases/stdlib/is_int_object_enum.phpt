--TEST--
is_int()/is_object() treat enum cases as objects not backing scalars (issue #5797; ext/standard/type.c)
--FILE--
<?php
enum EI: int { case A = 1; }
enum ES: string { case Ab = 'Ab'; }
enum U { case X; }

var_dump(is_int(EI::A));
var_dump(is_integer(EI::A));
var_dump(is_object(EI::A));

var_dump(is_string(ES::Ab));
var_dump(is_object(ES::Ab));

var_dump(is_int(U::X));
var_dump(is_object(U::X));

var_dump(is_int(1));
var_dump(is_object(1));
--EXPECT--
bool(false)
bool(false)
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
bool(true)
bool(false)
