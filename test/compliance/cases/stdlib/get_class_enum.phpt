--TEST--
stdlib get_class() on enum case returns enum name (ext/standard/basic_functions.c, #5484)
--FILE--
<?php
enum E {
    case A;
    case B;
}

enum Status: string {
    case OK = 'ok';
}

var_dump(get_class(E::A));
var_dump(get_class(E::B));
var_dump(get_class(Status::OK));

class Plain
{
}

var_dump(get_class(new Plain()));
--EXPECT--
string(1) "E"
string(1) "E"
string(6) "Status"
string(5) "Plain"
