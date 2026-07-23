--TEST--
Language: unit enum ->value emits Undefined property warning (#22523, Zend/zend_enum.c)
--FILE--
<?php
error_reporting(E_ALL);
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

enum Color { case Red; }
enum Status: int { case Ok = 1; }

echo 'name=', Color::Red->name, "\n";
$v = Color::Red->value;
echo 'unit_value=', var_export($v, true), "\n";
echo 'isset=', var_export(isset(Color::Red->value), true), "\n";
echo 'pex=', var_export(property_exists(Color::Red, 'value'), true), "\n";
// Assign form — inline empty(Color::Red->value) inside var_export still emits a
// dead PropertyFetch prelude (#8901); empty itself uses TYPE_EMPTY_OBJECT_PROPERTY.
$empty = empty(Color::Red->value);
echo 'empty=', var_export($empty, true), "\n";
echo 'backed=', var_export(Status::Ok->value, true), "\n";
--EXPECT--
name=Red
W:Undefined property: Color::$value
unit_value=NULL
isset=false
pex=false
empty=true
backed=1
