--TEST--
language is_*() zero-arg — ArgumentCountError, no CFG crash (#21961, Zend/zend_builtin_functions.c)
--FILE--
<?php
foreach ([
    'is_array',
    'is_string',
    'is_int',
    'is_null',
    'is_object',
    'is_bool',
    'is_float',
    'is_callable',
] as $label) {
    try {
        switch ($label) {
            case 'is_array': is_array(); break;
            case 'is_string': is_string(); break;
            case 'is_int': is_int(); break;
            case 'is_null': is_null(); break;
            case 'is_object': is_object(); break;
            case 'is_bool': is_bool(); break;
            case 'is_float': is_float(); break;
            case 'is_callable': is_callable(); break;
        }
        echo "$label: ran\n";
    } catch (Throwable $e) {
        echo "$label: ", get_class($e), ": ", $e->getMessage(), "\n";
    }
}
var_dump(is_array([]));
var_dump(is_string('x'));
var_dump(is_int(1));
var_dump(is_callable('strlen'));
--EXPECT--
is_array: ArgumentCountError: is_array() expects exactly 1 argument, 0 given
is_string: ArgumentCountError: is_string() expects exactly 1 argument, 0 given
is_int: ArgumentCountError: is_int() expects exactly 1 argument, 0 given
is_null: ArgumentCountError: is_null() expects exactly 1 argument, 0 given
is_object: ArgumentCountError: is_object() expects exactly 1 argument, 0 given
is_bool: ArgumentCountError: is_bool() expects exactly 1 argument, 0 given
is_float: ArgumentCountError: is_float() expects exactly 1 argument, 0 given
is_callable: ArgumentCountError: is_callable() expects at least 1 argument, 0 given
bool(true)
bool(true)
bool(true)
bool(true)
