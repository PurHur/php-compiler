--TEST--
Stdlib: enum_exists() false before enum declaration in same unit (#5013)
--FILE--
<?php
var_dump(enum_exists('NotYet'));
enum NotYet { case A; }
var_dump(enum_exists('NotYet'));
var_dump(enum_exists('NotYet', false));
--EXPECT--
bool(false)
bool(true)
bool(true)
