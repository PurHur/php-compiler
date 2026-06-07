--TEST--
Stdlib: get_parent_class() on enum case returns false (VM, #6335)
--FILE--
<?php
enum E: string
{
    case A = 'x';
}

var_export(get_parent_class(E::A));
--EXPECT--
false
