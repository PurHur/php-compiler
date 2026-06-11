--TEST--
AOT get_class() on enum case returns enum name (#5484)
--FILE--
<?php
enum E {
    case A;
}

enum Status: string {
    case OK = 'ok';
}

var_dump(get_class(E::A));
var_dump(get_class(Status::OK));
--EXPECT--
string(1) "E"
string(6) "Status"
