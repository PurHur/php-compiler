--TEST--
Language: file-scope const with new expression — ::class and identity (#5403)
--FILE--
<?php
const FOO = new stdClass();
var_dump(FOO::class);
var_dump(FOO === FOO);
--EXPECT--
string(3) "FOO"
bool(true)
